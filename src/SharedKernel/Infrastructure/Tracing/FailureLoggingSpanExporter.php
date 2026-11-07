<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs one warning when the wrapped exporter fails to export a batch, and drops the batch. The OTLP exporter
 * reports a failure only as a false result and to the SDK's own logger (which TracerProviderFactory turns off),
 * so nothing else would show a collector that is down. The warning carries no message text, only the exception
 * class when there is one: the message can quote the collector URL or a payload.
 */
final readonly class FailureLoggingSpanExporter implements SpanExporterInterface
{
    private const string FAILURE_MESSAGE = 'Span export failed; the batch was dropped.';

    public function __construct(private SpanExporterInterface $exporter, private LoggerInterface $logger)
    {
    }

    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        try {
            return $this->exporter->export($batch, $cancellation)
                ->map(function (mixed $isExported): mixed {
                    if (false === $isExported) {
                        $this->logger->warning(self::FAILURE_MESSAGE);
                    }

                    return $isExported;
                })
                ->catch(function (\Throwable $failure): bool {
                    $this->logger->warning(self::FAILURE_MESSAGE, ['exception_class' => $failure::class]);

                    return false;
                });
        } catch (\Throwable $failure) {
            $this->logger->warning(self::FAILURE_MESSAGE, ['exception_class' => $failure::class]);

            return new CompletedFuture(false);
        }
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->shutdown($cancellation);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->forceFlush($cancellation);
    }
}
