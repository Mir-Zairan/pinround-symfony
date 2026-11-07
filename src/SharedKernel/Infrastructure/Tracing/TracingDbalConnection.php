<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal built by TracingDbalDriver; the contract is on TracingDbalMiddleware
 */
final class TracingDbalConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $wrappedConnection, private readonly TracingDbalMiddleware $tracing)
    {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingDbalStatement(parent::prepare($sql), $this->tracing, $sql);
    }

    public function query(string $sql): Result
    {
        $span = $this->tracing->startStatementSpan($sql);

        return null === $span ? parent::query($sql) : SpanScope::runAndEnd($span, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        $span = $this->tracing->startStatementSpan($sql);

        return null === $span ? parent::exec($sql) : SpanScope::runAndEnd($span, fn (): int|string => parent::exec($sql));
    }

    public function beginTransaction(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::BEGIN);
        if (null === $span) {
            parent::beginTransaction();

            return;
        }

        SpanScope::runAndEnd($span, fn () => parent::beginTransaction());
    }

    public function commit(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::COMMIT);
        if (null === $span) {
            parent::commit();

            return;
        }

        SpanScope::runAndEnd($span, fn () => parent::commit());
    }

    public function rollBack(): void
    {
        $span = $this->tracing->startTransactionSpan(SqlKind::ROLLBACK);
        if (null === $span) {
            parent::rollBack();

            return;
        }

        SpanScope::runAndEnd($span, fn () => parent::rollBack());
    }
}
