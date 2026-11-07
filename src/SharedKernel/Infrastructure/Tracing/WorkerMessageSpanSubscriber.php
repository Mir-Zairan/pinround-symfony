<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Ends and exports the process span of a message at the end of the worker's turn for it, after the retry and failure
 * listeners and the "handled successfully" line have logged, so those lines carry trace_id and span_id. The turn ends
 * with the WorkerRunningEvent the worker dispatches after each message, once it has acknowledged it.
 * TracingMessengerMiddleware leaves the span open for a message that carries SpanEndedByWorkerStamp.
 */
final readonly class WorkerMessageSpanSubscriber
{
    public function __construct(
        private CurrentSpan $currentSpan,
        private RootSpanExporter $rootSpanExporter,
    ) {
    }

    /** A span a failed worker turn left behind is exported first, so it never becomes the parent of the next message. */
    #[AsEventListener(event: WorkerMessageReceivedEvent::class, priority: 1000)]
    public function markEndedByWorker(WorkerMessageReceivedEvent $event): void
    {
        $this->endOpenSpan();
        $event->addStamps(new SpanEndedByWorkerStamp());
    }

    #[AsEventListener(event: WorkerRunningEvent::class, priority: -1000)]
    public function endProcessSpan(WorkerRunningEvent $event): void
    {
        $this->endOpenSpan();
    }

    private function endOpenSpan(): void
    {
        $span = $this->currentSpan->span();
        if (null !== $span) {
            $this->rootSpanExporter->endAndExport($span);
        }
    }
}
