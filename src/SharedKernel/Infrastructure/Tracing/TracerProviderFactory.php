<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Nyholm\Psr7\Factory\Psr17Factory;
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
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the tracer provider from the OTEL_* environment values, without the SDK's own environment autoloading
 * or any global. An empty endpoint gives a no-op provider, unless a test hands in its own exporter.
 *
 * Spans export one by one as they end; the request span ends on kernel.terminate, after the response is sent.
 */
final readonly class TracerProviderFactory
{
    /** The whole export of one request, connect included, so a slow collector cannot hold the worker. */
    private const float EXPORT_TIMEOUT_SECONDS = 1.0;

    public function __construct(
        private string $endpoint,
        private string $serviceName,
        private string $samplingRatio,
        private string $protocol,
        private ?SpanExporterInterface $exporter = null,
    ) {
    }

    public function create(): TracerProviderInterface
    {
        $exporter = $this->exporter;
        if (null === $exporter) {
            if ('' === $this->endpoint) {
                return new NoopTracerProvider();
            }

            // A failed export is dropped; the SDK would otherwise print the exception to stderr.
            LoggerHolder::disable();
            $exporter = $this->createOtlpExporter();
        }

        return new TracerProvider(
            new SimpleSpanProcessor($exporter),
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
            HttpClient::create(['timeout' => self::EXPORT_TIMEOUT_SECONDS, 'max_duration' => self::EXPORT_TIMEOUT_SECONDS]),
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
