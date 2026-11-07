<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the tracer provider from the OTEL_* environment values, without the SDK's own environment autoloading
 * and without OpenTelemetry\API\Globals. An empty endpoint gives a no-op provider, unless a test hands in its own
 * exporter.
 *
 * Ended spans queue in memory and leave only on forceFlush, which RequestSpanSubscriber calls on kernel.terminate,
 * after the response is sent: no query span blocks the request. The queue is bounded, and spans past it are dropped.
 *
 * One process-wide side effect, on purpose: building a provider with the OTLP exporter calls
 * LoggerHolder::disable(), so the SDK's internal diagnostics (which quote exception messages) never reach stderr.
 * FailureLoggingSpanExporter reports a failed export instead.
 */
final readonly class TracerProviderFactory
{
    /**
     * Caps one HTTP export call to the collector (the connection idle time and the whole call), so a slow collector
     * cannot hold the worker for long after the response. It is not a cap on a request's total tracing work.
     */
    private const float EXPORT_TIMEOUT_SECONDS = 1.0;
    // Up to 2048 spans per request are queued and leave in batches of 512 (one HTTP call each, each capped at 1 s);
    // spans past 2048 are dropped.
    private const int MAX_QUEUE_SIZE = 2048;
    private const int MAX_EXPORT_BATCH_SIZE = 512;
    // Never reached: the processor runs without auto flush, so only forceFlush exports.
    private const int SCHEDULE_DELAY_MILLIS = 600_000;

    public function __construct(
        private string $endpoint,
        private string $serviceName,
        private string $samplingRatio,
        private string $protocol,
        private LoggerInterface $logger,
        private ?SpanExporterInterface $exporter = null,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function create(): TracerProviderInterface
    {
        $exporter = $this->exporter;
        if (null === $exporter) {
            if ('' === $this->endpoint) {
                return new NoopTracerProvider();
            }

            LoggerHolder::disable();
            $exporter = $this->createOtlpExporter();
        }

        return new TracerProvider(
            new BatchSpanProcessor(
                new FailureLoggingSpanExporter($exporter, $this->logger),
                SystemClock::create(),
                self::MAX_QUEUE_SIZE,
                self::SCHEDULE_DELAY_MILLIS,
                (int) (self::EXPORT_TIMEOUT_SECONDS * 1000),
                self::MAX_EXPORT_BATCH_SIZE,
                autoFlush: false,
            ),
            new ParentBased(new TraceIdRatioBasedSampler($this->parseSamplingRatio())),
            ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
                'service.name' => '' === $this->serviceName ? 'pinround' : $this->serviceName,
            ]))),
        );
    }

    private function createOtlpExporter(): SpanExporterInterface
    {
        $psr17Factory = new Psr17Factory();
        $client = new Psr18Client(
            ($this->httpClient ?? HttpClient::create())->withOptions(['timeout' => self::EXPORT_TIMEOUT_SECONDS, 'max_duration' => self::EXPORT_TIMEOUT_SECONDS]),
            $psr17Factory,
            $psr17Factory,
        );

        return new SpanExporter(new PsrTransportFactory($client, $psr17Factory, $psr17Factory)->create(
            rtrim($this->endpoint, '/').'/v1/traces',
            'http/json' === $this->protocol ? ContentTypes::JSON : ContentTypes::PROTOBUF,
            timeout: self::EXPORT_TIMEOUT_SECONDS,
            maxRetries: 0,
        ));
    }

    /** A missing or unreadable ratio samples everything; one outside 0..1 is clamped. */
    private function parseSamplingRatio(): float
    {
        if (!is_numeric($this->samplingRatio)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $this->samplingRatio));
    }
}
