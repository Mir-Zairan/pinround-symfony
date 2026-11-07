<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * One child span of CurrentSpan per statement the default connection sends (query, exec, prepared execute) and per
 * BEGIN, COMMIT and ROLLBACK, named "db {KIND}" (SqlKind): never the SQL text, a value or a table name (ADR-0029).
 * Without a recording active span (tracing off, or the request not sampled) a statement costs one recording check:
 * no span, no SqlKind, no allocation.
 */
// Priority above TenantMiddleware's 0 puts this one inside it, so the tenant statement TenantConnection prepares runs through it.
#[AsMiddleware(connections: ['default'], priority: 10)]
final readonly class TracingDbalMiddleware implements Middleware
{
    private const string INSTRUMENTATION_NAME = 'pinround.db';

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TracingDbalDriver($driver, $this);
    }

    /**
     * @internal called by the wrapped connection and statement
     */
    public function startStatementSpan(string $sql): ?SpanInterface
    {
        $parentContext = $this->currentSpan->recordingContext();

        return null === $parentContext ? null : $this->startSpan(SqlKind::of($sql), $parentContext);
    }

    /**
     * @internal called by the wrapped connection
     *
     * @param SqlKind::BEGIN|SqlKind::COMMIT|SqlKind::ROLLBACK $kind
     */
    public function startTransactionSpan(string $kind): ?SpanInterface
    {
        $parentContext = $this->currentSpan->recordingContext();

        return null === $parentContext ? null : $this->startSpan($kind, $parentContext);
    }

    private function startSpan(string $kind, ContextInterface $parentContext): SpanInterface
    {
        return $this->tracerProvider->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder('db '.$kind)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(SpanAttributes::DB_SYSTEM_NAME, 'postgresql')
            ->setAttribute(SpanAttributes::DB_OPERATION_NAME, $kind)
            ->startSpan();
    }
}
