<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @internal built by TracingDbalMiddleware
 */
final class TracingDbalDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver, private readonly TracingDbalMiddleware $tracing)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): Connection {
        return new TracingDbalConnection(parent::connect($params), $this->tracing);
    }
}
