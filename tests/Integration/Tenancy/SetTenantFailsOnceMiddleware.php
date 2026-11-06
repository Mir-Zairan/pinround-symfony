<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;

/**
 * Placed inside TenantMiddleware, it makes the first `set_config('app.org_id', …)` fail after the real BEGIN.
 */
final class SetTenantFailsOnceMiddleware implements Middleware
{
    public readonly \RuntimeException $failure;

    private bool $hasFailed = false;

    public function __construct()
    {
        $this->failure = new \RuntimeException('set_config failed on purpose');
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly SetTenantFailsOnceMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->middleware) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly SetTenantFailsOnceMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        if (str_contains($sql, "set_config('app.org_id'")) {
                            $this->middleware->failTheFirstTime();
                        }

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }

    public function failTheFirstTime(): void
    {
        if ($this->hasFailed) {
            return;
        }

        $this->hasFailed = true;

        throw $this->failure;
    }
}
