<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * One child span of CurrentSpan per statement the default connection sends (query, exec, prepared execute) and per
 * BEGIN, COMMIT and ROLLBACK, named "db {KIND}" (SqlKind): never the SQL text, a value or a table name (ADR-0029).
 * Without an active span a statement costs one null check.
 */
// Priority above TenantMiddleware's 0 puts this one inside it, so the tenant statement TenantConnection prepares runs through it.
#[AsMiddleware(connections: ['default'], priority: 10)]
final readonly class TracingMiddleware implements Middleware
{
    private const string INSTRUMENTATION_NAME = 'pinround.db';

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private CurrentSpan $currentSpan,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, $this);
    }

    /**
     * @internal called by the wrapped connection and statement
     */
    public function startStatementSpan(string $sql): ?SpanInterface
    {
        return null === $this->currentSpan->context() ? null : $this->startSpan(SqlKind::of($sql));
    }

    /**
     * @internal called by the wrapped connection
     *
     * @param SqlKind::BEGIN|SqlKind::COMMIT|SqlKind::ROLLBACK $kind
     */
    public function startTransactionSpan(string $kind): ?SpanInterface
    {
        return null === $this->currentSpan->context() ? null : $this->startSpan($kind);
    }

    private function startSpan(string $kind): SpanInterface
    {
        return $this->tracerProvider->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder('db '.$kind)
            ->setParent($this->currentSpan->context())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute(SpanAttributes::DB_SYSTEM_NAME, 'postgresql')
            ->setAttribute(SpanAttributes::DB_OPERATION_NAME, $kind)
            ->startSpan();
    }
}
