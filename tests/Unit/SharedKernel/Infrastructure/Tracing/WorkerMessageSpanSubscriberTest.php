<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\SharedKernel\Infrastructure\Tracing\RootSpanExporter;
use App\SharedKernel\Infrastructure\Tracing\SpanEndedByWorkerStamp;
use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use App\SharedKernel\Infrastructure\Tracing\WorkerMessageSpanSubscriber;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

final class WorkerMessageSpanSubscriberTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProviderInterface $provider;
    private CurrentSpan $currentSpan;
    private WorkerMessageSpanSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProviderFactory('', 'pinround', '1', 'http/protobuf', new NullLogger(), $this->exporter)->create();
        $this->currentSpan = new CurrentSpan();
        $this->subscriber = new WorkerMessageSpanSubscriber($this->currentSpan, new RootSpanExporter($this->provider, $this->currentSpan, new NullLogger()));
    }

    #[Test]
    public function aReceivedMessageIsStampedSoTheBusLeavesItsSpanToTheWorker(): void
    {
        $event = new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');

        $this->subscriber->markEndedByWorker($event);

        self::assertNotNull($event->getEnvelope()->last(SpanEndedByWorkerStamp::class));
    }

    #[Test]
    public function aSpanLeftOverFromAnEarlierMessageIsExportedAndClearedBeforeTheNextOneStarts(): void
    {
        $this->beginSpan('left over');

        $this->subscriber->markEndedByWorker(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));

        self::assertNull($this->currentSpan->span());
        self::assertSame(['left over'], array_map(static fn ($span): string => $span->getName(), ExportedSpans::of($this->exporter)));
    }

    #[Test]
    public function theTurnEndingExportsTheOpenSpanAndClearsIt(): void
    {
        $this->beginSpan('process');

        $this->subscriber->endProcessSpan(new WorkerRunningEvent(new Worker([], new MessageBus()), false));

        self::assertNull($this->currentSpan->span());
        self::assertCount(1, ExportedSpans::of($this->exporter));
    }

    #[Test]
    public function anIdleTurnWithNoOpenSpanExportsNothing(): void
    {
        $this->subscriber->endProcessSpan(new WorkerRunningEvent(new Worker([], new MessageBus()), true));

        self::assertSame([], ExportedSpans::of($this->exporter));
    }

    /**
     * @param non-empty-string $name
     */
    private function beginSpan(string $name): void
    {
        $span = $this->provider->getTracer('probe')->spanBuilder($name)->setParent(false)->startSpan();
        $this->currentSpan->begin($span, $span->storeInContext(Context::getRoot()));
    }
}
