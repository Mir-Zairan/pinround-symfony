<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tracing;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\SpanAttributes;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Fixtures\Tracing\TracedProbeMessage;
use App\Tests\Fixtures\Tracing\TracedProbeMessageHandler;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Messenger spans through the real buses and the worker loop, with an in-memory exporter (ADR-0029).
 */
final class TracingMessengerMiddlewareTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    #[Test]
    public function eachMessageTheWorkerHandlesGetsItsOwnTraceWithTheAllowedAttributesAndItsQueriesAsChildren(): void
    {
        $marker = 'message-marker-'.bin2hex(random_bytes(6));
        $commandBus = $this->bus('command.bus');
        $commandBus->dispatch(new TracedProbeMessage($marker), [new TransportNamesStamp(['test_queue'])]);
        $commandBus->dispatch(new TracedProbeMessage($marker), [new TransportNamesStamp(['test_queue'])]);
        self::assertSame([], ExportedSpans::of($this->exporter()), 'Sending outside a span records nothing.');

        self::runTheWorkerUntilIdle('test_queue');

        $spans = ExportedSpans::of($this->exporter());
        $processSpans = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => 'messenger process TracedProbeMessage' === $span->getName()));
        self::assertCount(2, $processSpans);
        self::assertNotSame($processSpans[0]->getTraceId(), $processSpans[1]->getTraceId());
        foreach ($processSpans as $processSpan) {
            self::assertFalse(SpanContextValidator::isValidSpanId($processSpan->getParentSpanId()));
            self::assertEqualsCanonicalizing([
                SpanAttributes::MESSAGING_SYSTEM => 'symfony_messenger',
                SpanAttributes::MESSAGING_OPERATION_TYPE => 'process',
                SpanAttributes::MESSAGING_DESTINATION_NAME => 'command.bus',
                SpanAttributes::MESSAGING_MESSAGE_CLASS => 'TracedProbeMessage',
            ], $processSpan->getAttributes()->toArray());
            self::assertSame(StatusCode::STATUS_UNSET, $processSpan->getStatus()->getCode());

            $children = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => $span->getParentSpanId() === $processSpan->getSpanId()));
            self::assertSame(['db BEGIN', 'db SELECT', 'db COMMIT'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $children));
        }
        self::assertCount(8, $spans);
        self::assertStringNotContainsString($marker, serialize($this->visibleText($spans)));
        self::assertNull(self::getContainer()->get(CurrentSpan::class)->span());
    }

    #[Test]
    public function aHandlerThatThrowsMarksTheProcessSpanAsAnErrorAndTheExceptionPropagates(): void
    {
        $failure = null;
        try {
            $this->bus('command.bus')->dispatch(new TracedProbeMessage('x', isFailing: true), [new ReceivedStamp('test_queue')]);
        } catch (HandlerFailedException $failure) {
        }

        self::assertInstanceOf(HandlerFailedException::class, $failure);
        $spans = ExportedSpans::of($this->exporter());
        $processSpans = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => 'messenger process TracedProbeMessage' === $span->getName()));
        self::assertCount(1, $processSpans);
        self::assertSame(StatusCode::STATUS_ERROR, $processSpans[0]->getStatus()->getCode());
        self::assertSame('', $processSpans[0]->getStatus()->getDescription());
        self::assertSame(HandlerFailedException::class, $processSpans[0]->getAttributes()->get(SpanAttributes::ERROR_TYPE));
        self::assertStringNotContainsString(TracedProbeMessageHandler::FAILURE_MESSAGE, serialize($this->visibleText($spans)));
        self::assertNull(self::getContainer()->get(CurrentSpan::class)->span());
    }

    #[Test]
    public function aFailingMessageDoesNotMarkTheTraceOfTheNextMessageAndEachHasItsOwnTrace(): void
    {
        self::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM messenger_failed');
        $commandBus = $this->bus('command.bus');
        $commandBus->dispatch(new TracedProbeMessage('first', isFailing: true), [new TransportNamesStamp(['test_queue'])]);
        $commandBus->dispatch(new TracedProbeMessage('second'), [new TransportNamesStamp(['test_queue'])]);

        self::runTheWorkerUntilIdle('test_queue');

        $processSpans = array_values(array_filter(
            ExportedSpans::of($this->exporter()),
            static fn (SpanDataInterface $span): bool => 'messenger process TracedProbeMessage' === $span->getName(),
        ));
        self::assertCount(2, $processSpans);
        self::assertSame(StatusCode::STATUS_ERROR, $processSpans[0]->getStatus()->getCode());
        self::assertSame(HandlerFailedException::class, $processSpans[0]->getAttributes()->get(SpanAttributes::ERROR_TYPE));
        self::assertSame(StatusCode::STATUS_UNSET, $processSpans[1]->getStatus()->getCode());
        self::assertFalse($processSpans[1]->getAttributes()->has(SpanAttributes::ERROR_TYPE));
        self::assertNotSame($processSpans[0]->getTraceId(), $processSpans[1]->getTraceId());
        self::assertFalse(SpanContextValidator::isValidSpanId($processSpans[0]->getParentSpanId()));
        self::assertFalse(SpanContextValidator::isValidSpanId($processSpans[1]->getParentSpanId()));
        self::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('DELETE FROM messenger_failed');
    }

    #[Test]
    public function aMessageDispatchedFromInsideAHandlerGetsASendSpanUnderTheProcessSpan(): void
    {
        $this->bus('command.bus')->dispatch(new TracedProbeMessage('x', dispatchesFollowUp: true), [new TransportNamesStamp(['test_queue'])]);

        self::runTheWorkerUntilIdle('test_queue');

        $spans = ExportedSpans::of($this->exporter());
        $processSpans = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => 'messenger process TracedProbeMessage' === $span->getName()));
        $sendSpans = array_values(array_filter($spans, static fn (SpanDataInterface $span): bool => 'messenger send stdClass' === $span->getName()));
        self::assertCount(1, $processSpans);
        self::assertCount(1, $sendSpans);
        self::assertSame($processSpans[0]->getSpanId(), $sendSpans[0]->getParentSpanId());
        self::assertSame($processSpans[0]->getTraceId(), $sendSpans[0]->getTraceId());
        self::assertSame('event.bus', $sendSpans[0]->getAttributes()->get(SpanAttributes::MESSAGING_DESTINATION_NAME));
    }

    #[Test]
    public function aSendThatThrowsMarksTheSendSpanAsAnErrorWithTheExceptionClassAndPropagates(): void
    {
        $span = self::getContainer()->get(TracerProviderInterface::class)->getTracer('test')->spanBuilder('test root')->setParent(false)->startSpan();
        self::getContainer()->get(CurrentSpan::class)->begin($span, $span->storeInContext(Context::getRoot()));
        $thrown = null;

        try {
            $this->bus('command.bus')->dispatch(new TracedProbeMessage('x'), [new TransportNamesStamp(['unreachable_valkey'])]);
        } catch (\Throwable $thrown) {
        }

        self::assertNotNull($thrown, 'The unreachable transport must make the dispatch fail.');
        self::getContainer()->get(TracerProviderInterface::class)->forceFlush();
        $sendSpans = array_values(array_filter(ExportedSpans::of($this->exporter()), static fn (SpanDataInterface $exported): bool => 'messenger send TracedProbeMessage' === $exported->getName()));
        self::assertCount(1, $sendSpans);
        self::assertSame(StatusCode::STATUS_ERROR, $sendSpans[0]->getStatus()->getCode());
        self::assertSame('', $sendSpans[0]->getStatus()->getDescription());
        self::assertSame($thrown::class, $sendSpans[0]->getAttributes()->get(SpanAttributes::ERROR_TYPE));
    }

    #[Test]
    public function aMessageDispatchedInsideAnActiveSpanGetsASendChildSpanOnItsBus(): void
    {
        $span = self::getContainer()->get(TracerProviderInterface::class)->getTracer('test')->spanBuilder('test root')->setParent(false)->startSpan();
        self::getContainer()->get(CurrentSpan::class)->begin($span, $span->storeInContext(Context::getRoot()));

        $this->bus('event.bus')->dispatch(new \stdClass());

        self::getContainer()->get(TracerProviderInterface::class)->forceFlush();
        $sendSpans = array_values(array_filter(ExportedSpans::of($this->exporter()), static fn (SpanDataInterface $exported): bool => 'messenger send stdClass' === $exported->getName()));
        self::assertCount(1, $sendSpans);
        self::assertSame($span->getContext()->getSpanId(), $sendSpans[0]->getParentSpanId());
        self::assertSame($span->getContext()->getTraceId(), $sendSpans[0]->getTraceId());
        self::assertEqualsCanonicalizing([
            SpanAttributes::MESSAGING_SYSTEM => 'symfony_messenger',
            SpanAttributes::MESSAGING_OPERATION_TYPE => 'send',
            SpanAttributes::MESSAGING_DESTINATION_NAME => 'event.bus',
            SpanAttributes::MESSAGING_MESSAGE_CLASS => 'stdClass',
        ], $sendSpans[0]->getAttributes()->toArray());
        self::assertSame($span, self::getContainer()->get(CurrentSpan::class)->span(), 'A send span leaves the active span alone.');
    }

    private function bus(string $busId): MessageBusInterface
    {
        $bus = self::getContainer()->get($busId);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function exporter(): InMemoryExporter
    {
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        return $exporter;
    }

    /**
     * @param list<SpanDataInterface> $spans
     *
     * @return list<array<string, mixed>>
     */
    private function visibleText(array $spans): array
    {
        return array_map(static fn (SpanDataInterface $span): array => [
            'name' => $span->getName(),
            'attributes' => $span->getAttributes()->toArray(),
            'events' => $span->getEvents(),
            'status' => $span->getStatus()->getDescription(),
        ], $spans);
    }
}
