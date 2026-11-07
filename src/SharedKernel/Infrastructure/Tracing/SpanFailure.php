<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * How a failed operation shows on its span: status ERROR and the exception class as error.type, never the message.
 */
final class SpanFailure
{
    public static function record(SpanInterface $span, \Throwable $failure): void
    {
        $span->setAttribute(SpanAttributes::ERROR_TYPE, $failure::class);
        $span->setStatus(StatusCode::STATUS_ERROR);
    }

    private function __construct()
    {
    }
}
