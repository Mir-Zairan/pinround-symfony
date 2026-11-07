<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Spans for Messenger, on every bus. A message a worker received, with no span active, gets a root span "messenger
 * process {Class}" that ends and is exported when the message is done, so the next message starts a new trace. A message
 * dispatched while a span is active (inside a request or a handler) gets a child span "messenger send {Class}". With no
 * span active and no ReceivedStamp (a console command) nothing is recorded. Attributes: the system, the operation, the
 * bus and the short class name; never message content (ADR-0029). Listed after add_bus_name_stamp_middleware, whose
 * stamp names the bus, and before every other middleware, so the span covers the tenant ones.
 */
final readonly class TracingMessengerMiddleware implements MiddlewareInterface
{
    private const string INSTRUMENTATION_NAME = 'pinround.messenger';
    private const string OPERATION_SEND = 'send';
    private const string OPERATION_PROCESS = 'process';

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
        private RootSpanExporter $rootSpanExporter,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $parentContext = $this->currentSpan->context();
        if (null !== $parentContext) {
            $span = $this->startSpan($envelope, self::OPERATION_SEND, SpanKind::KIND_PRODUCER, $parentContext);

            return $this->runInSpan($span, $envelope, $stack);
        }

        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $span = $this->startSpan($envelope, self::OPERATION_PROCESS, SpanKind::KIND_CONSUMER, null);
        $this->currentSpan->begin($span, $span->storeInContext(Context::getRoot()));

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $this->rootSpanExporter->endAndExport($span);
        }
    }

    private function runInSpan(SpanInterface $span, Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    /**
     * @param SpanKind::KIND_PRODUCER|SpanKind::KIND_CONSUMER $spanKind
     */
    private function startSpan(Envelope $envelope, string $operation, int $spanKind, ?ContextInterface $parentContext): SpanInterface
    {
        $messageClass = $this->shortClassName($envelope->getMessage());
        $builder = $this->tracerProvider->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder('messenger '.$operation.' '.$messageClass)
            ->setSpanKind($spanKind)
            ->setAttribute(SpanAttributes::MESSAGING_SYSTEM, 'symfony_messenger')
            ->setAttribute(SpanAttributes::MESSAGING_OPERATION_TYPE, $operation)
            ->setAttribute(SpanAttributes::MESSAGING_DESTINATION_NAME, $envelope->last(BusNameStamp::class)?->getBusName() ?? 'unknown')
            ->setAttribute(SpanAttributes::MESSAGING_MESSAGE_CLASS, $messageClass);

        return (null === $parentContext ? $builder->setParent(false) : $builder->setParent($parentContext))->startSpan();
    }

    private function shortClassName(object $message): string
    {
        $reflection = new \ReflectionClass($message);

        // The name of an anonymous class carries a file path.
        return $reflection->isAnonymous() ? 'anonymous' : $reflection->getShortName();
    }
}
