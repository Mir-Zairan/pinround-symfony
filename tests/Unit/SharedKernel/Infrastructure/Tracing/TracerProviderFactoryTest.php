<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TracerProviderFactoryTest extends TestCase
{
    #[Test]
    public function anEmptyEndpointGivesANoopProviderThatStartsNoValidSpan(): void
    {
        $provider = new TracerProviderFactory('', 'pinround', '1.0', 'http/protobuf')->create();

        self::assertInstanceOf(NoopTracerProvider::class, $provider);
        $span = $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan();
        self::assertFalse($span->getContext()->isValid());
        self::assertFalse($span->isRecording());
    }

    #[Test]
    public function anEndpointGivesAnSdkProvider(): void
    {
        $provider = new TracerProviderFactory('http://127.0.0.1:9', 'pinround', '1.0', 'http/protobuf')->create();

        self::assertInstanceOf(TracerProvider::class, $provider);
    }

    #[Test]
    public function theServiceNameIsExportedAsTheResource(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', 'checkout-test', '1.0', 'http/protobuf', $exporter)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();

        $span = ExportedSpans::of($exporter)[0];
        self::assertSame('checkout-test', $span->getResource()->getAttributes()->get('service.name'));
        self::assertNull($span->getResource()->getAttributes()->get('host.name'));
    }

    #[Test]
    public function anEmptyServiceNameFallsBackToPinround(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProviderFactory('', '', '1.0', 'http/protobuf', $exporter)->create();

        $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();

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
        $provider = new TracerProviderFactory('', 'pinround', $ratio, 'http/protobuf', $exporter)->create();

        for ($trace = 0; $trace < 20; ++$trace) {
            $provider->getTracer('probe')->spanBuilder('probe')->setParent(false)->startSpan()->end();
        }

        self::assertSame($isExported ? 20 : 0, \count(ExportedSpans::of($exporter)));
    }
}
