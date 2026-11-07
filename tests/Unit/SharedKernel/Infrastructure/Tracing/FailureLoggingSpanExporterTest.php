<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\FailureLoggingSpanExporter;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FailureLoggingSpanExporterTest extends TestCase
{
    #[Test]
    public function aSuccessfulExportLogsNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $isExported = new FailureLoggingSpanExporter(self::exporterReturning(new CompletedFuture(true)), $logger)->export([])->await();

        self::assertTrue($isExported);
    }

    #[Test]
    public function aFalseResultLogsOneWarningWithoutContext(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Span export failed; the batch was dropped.', []);

        $isExported = new FailureLoggingSpanExporter(self::exporterReturning(new CompletedFuture(false)), $logger)->export([])->await();

        self::assertFalse($isExported);
    }

    #[Test]
    public function aFailedFutureLogsTheExceptionClassOnly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Span export failed; the batch was dropped.', ['exception_class' => \RuntimeException::class]);

        $isExported = new FailureLoggingSpanExporter(self::exporterReturning(new ErrorFuture(new \RuntimeException('private detail'))), $logger)->export([])->await();

        self::assertFalse($isExported);
    }

    #[Test]
    public function anExporterThatThrowsLogsTheExceptionClassOnlyAndFailsTheFuture(): void
    {
        $throwing = self::createStub(SpanExporterInterface::class);
        $throwing->method('export')->willThrowException(new \LogicException('private detail'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Span export failed; the batch was dropped.', ['exception_class' => \LogicException::class]);

        self::assertFalse(new FailureLoggingSpanExporter($throwing, $logger)->export([])->await());
    }

    #[Test]
    public function flushAndShutdownPassThroughToTheWrappedExporter(): void
    {
        $inner = $this->createMock(SpanExporterInterface::class);
        $inner->expects(self::once())->method('forceFlush')->willReturn(true);
        $inner->expects(self::once())->method('shutdown')->willReturn(false);
        $exporter = new FailureLoggingSpanExporter($inner, self::createStub(LoggerInterface::class));

        self::assertTrue($exporter->forceFlush(self::createStub(CancellationInterface::class)));
        self::assertFalse($exporter->shutdown());
    }

    /**
     * @param FutureInterface<bool> $future
     */
    private static function exporterReturning(FutureInterface $future): SpanExporterInterface
    {
        $exporter = self::createStub(SpanExporterInterface::class);
        $exporter->method('export')->willReturn($future);

        return $exporter;
    }
}
