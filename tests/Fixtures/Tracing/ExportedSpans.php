<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Tracing;

use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * Test-only: the spans an in-memory exporter holds, typed (its own getSpans() returns a bare array).
 */
final class ExportedSpans
{
    /**
     * @return list<SpanDataInterface>
     */
    public static function of(InMemoryExporter $exporter): array
    {
        return array_values(array_filter($exporter->getSpans(), static fn (mixed $span): bool => $span instanceof SpanDataInterface));
    }

    private function __construct()
    {
    }
}
