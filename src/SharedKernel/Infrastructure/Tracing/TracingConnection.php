<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal built by TracingDriver; the contract is on TracingMiddleware
 */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $wrappedConnection, private readonly TracingMiddleware $tracing)
    {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->tracing, $sql);
    }

    public function query(string $sql): Result
    {
        $span = $this->tracing->startStatementSpan($sql);
        if (null === $span) {
            return parent::query($sql);
        }

        try {
            return parent::query($sql);
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    public function exec(string $sql): int|string
    {
        $span = $this->tracing->startStatementSpan($sql);
        if (null === $span) {
            return parent::exec($sql);
        }

        try {
            return parent::exec($sql);
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    public function beginTransaction(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::BEGIN);
        if (null === $span) {
            parent::beginTransaction();

            return;
        }

        try {
            parent::beginTransaction();
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    public function commit(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::COMMIT);
        if (null === $span) {
            parent::commit();

            return;
        }

        try {
            parent::commit();
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }

    public function rollBack(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::ROLLBACK);
        if (null === $span) {
            parent::rollBack();

            return;
        }

        try {
            parent::rollBack();
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }
}
