<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Ends a root span (a request's or a handled message's), sends the queued spans to the collector and clears
 * CurrentSpan so the next unit of work starts a new trace. A failing export is logged by class only and dropped.
 */
final readonly class RootSpanExporter
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
        private LoggerInterface $logger,
    ) {
    }

    public function endAndExport(SpanInterface $rootSpan): void
    {
        try {
            $rootSpan->end();
            $this->tracerProvider->forceFlush();
        } catch (\Throwable $exportFailure) {
            // Class only: the message can quote the collector URL or a payload.
            $this->logger->warning('Exporting a trace failed; it was dropped.', [
                'exception_class' => $exportFailure::class,
            ]);
        } finally {
            $this->currentSpan->reset();
        }
    }
}
