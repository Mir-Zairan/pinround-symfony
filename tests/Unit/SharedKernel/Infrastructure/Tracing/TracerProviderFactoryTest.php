<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TracerProviderFactoryTest extends TestCase
{
    #[Test]
    public function anEmptyEndpointGivesANoopProviderThatStartsNoValidSpan(): void
    {
        $provider = new TracerProviderFactory('', 'pinround', '1.0', 'http/protobuf', new NullLogger())->create();

        self::assertInstanceOf(NoopTracerProvider::class, $provider);
        $span = $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan();
        self::assertFalse($span->getContext()->isValid());
        self::assertFalse($span->isRecording());
    }

    #[Test]
    public function anEndpointGivesAnSdkProvider(): void
    {
        $provider = new TracerProviderFactory('http://collector.test:4318', 'pinround', '1.0', 'http/protobuf', new NullLogger())->create();

        self::assertInstanceOf(TracerProvider::class, $provider);
    }

    #[Test]
    public function theServiceNameIsExportedAsTheResource(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', 'checkout-test', '1.0', 'http/protobuf', new NullLogger(), $exporter)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        $provider->forceFlush();

        $span = ExportedSpans::of($exporter)[0];
        self::assertSame('checkout-test', $span->getResource()->getAttributes()->get('service.name'));
        self::assertNull($span->getResource()->getAttributes()->get('host.name'));
    }

    #[Test]
    public function anEmptyServiceNameFallsBackToPinround(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', '', '1.0', 'http/protobuf', new NullLogger(), $exporter)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        $provider->forceFlush();

        self::assertSame('pinround', ExportedSpans::of($exporter)[0]->getResource()->getAttributes()->get('service.name'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function samplingRatios(): iterable
    {
        yield 'everything' => ['1.0', true];
        yield 'nothing' => ['0', false];
        yield 'above one is clamped to everything' => ['7', true];
        yield 'below zero is clamped to nothing' => ['-1', false];
        yield 'unreadable samples everything' => ['', true];
    }

    #[Test]
    #[DataProvider('samplingRatios')]
    public function theSamplingRatioDecidesWhetherANewTraceIsExported(string $ratio, bool $isExported): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', 'pinround', $ratio, 'http/protobuf', new NullLogger(), $exporter)->create();

        for ($trace = 0; $trace < 20; ++$trace) {
            $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        }
        $provider->forceFlush();

        self::assertSame($isExported ? 20 : 0, \count(ExportedSpans::of($exporter)));
    }

    #[Test]
    public function aSpanEndedBeforeTheRequestEndsIsNotExportedUntilTheFlush(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', 'pinround', '1.0', 'http/protobuf', new NullLogger(), $exporter)->create();
        $tracer = $provider->getTracer('probe');
        $root = $tracer->spanBuilder('root')->setParent(false)->startSpan();
        $rootContext = $root->storeInContext(Context::getRoot());

        $tracer->spanBuilder('child')->setParent($rootContext)->startSpan()->end();
        self::assertCount(0, $exporter->getStorage());

        $root->end();
        self::assertCount(0, $exporter->getStorage());

        $provider->forceFlush();
        $names = [];
        foreach (ExportedSpans::of($exporter) as $span) {
            $names[] = $span->getName();
        }
        self::assertEqualsCanonicalizing(['root', 'child'], $names);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protocols(): iterable
    {
        yield 'protobuf by default' => ['http/protobuf', 'application/x-protobuf'];
        yield 'json when asked' => ['http/json', 'application/json'];
        yield 'protobuf for an unknown value' => ['grpc', 'application/x-protobuf'];
    }

    #[Test]
    #[DataProvider('protocols')]
    public function theOtlpExportPostsToTheTracesPathInTheChosenFormatWithTheOneSecondLimits(string $protocol, string $contentType): void
    {
        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });
        $provider = new TracerProviderFactory('http://collector.test:4318/', 'pinround', '1.0', $protocol, new NullLogger(), null, $client)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        $provider->forceFlush();

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://collector.test:4318/v1/traces', $requests[0]['url']);
        self::assertSame(1.0, $requests[0]['options']['timeout']);
        self::assertSame(1.0, $requests[0]['options']['max_duration']);
        $headers = $requests[0]['options']['headers'];
        self::assertIsArray($headers);
        self::assertContains('Content-Type: '.$contentType, $headers);
    }

    #[Test]
    public function aCollectorErrorLogsOneWarningWithoutItsMessageAndThrowsNothing(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'collector-secret-detail']));
        $warnings = [];
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$warnings): void {
            $warnings[] = [(string) $message, $context];
        });
        $provider = new TracerProviderFactory('http://collector.test:4318', 'pinround', '1.0', 'http/protobuf', $logger, null, $client)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        $provider->forceFlush();

        self::assertSame([['Span export failed; the batch was dropped.', []]], $warnings);
    }
}
