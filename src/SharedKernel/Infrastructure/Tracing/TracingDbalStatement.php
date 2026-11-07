<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * @internal built by TracingDbalConnection; the contract is on TracingDbalMiddleware
 */
final class TracingDbalStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $wrappedStatement, private readonly TracingDbalMiddleware $tracing, private readonly string $sql)
    {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        $span = $this->tracing->startStatementSpan($this->sql);

        return null === $span ? parent::execute() : SpanScope::runAndEnd($span, fn (): Result => parent::execute());
    }
}
