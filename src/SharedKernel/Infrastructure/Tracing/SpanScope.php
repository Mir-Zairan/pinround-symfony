<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;

/**
 * Runs one operation inside an already started child span: a failure marks the span (SpanFailure) and is rethrown
 * unchanged, and the span always ends.
 */
final class SpanScope
{
    /**
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    public static function runAndEnd(SpanInterface $span, \Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    private function __construct()
    {
    }
}
