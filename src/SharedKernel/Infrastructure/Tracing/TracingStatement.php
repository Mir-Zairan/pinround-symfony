<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal built by TracingConnection; the contract is on TracingMiddleware
 */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $wrappedStatement, private readonly TracingMiddleware $tracing, private readonly string $sql)
    {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        $span = $this->tracing->startStatementSpan($this->sql);
        if (null === $span) {
            return parent::execute();
        }

        try {
            return parent::execute();
        } catch (\Throwable $failure) {
            SpanFailure::record($span, $failure);

            throw $failure;
        } finally {
            $span->end();
        }
    }
}
