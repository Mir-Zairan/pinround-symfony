<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records the SQL of every statement sent through it. Placed outside TenantMiddleware, it does not see the
 * set_config that sets the tenant, so it counts only the caller's own queries.
 */
final class RecordedSql implements Middleware
{
    /** @var list<string> */
    public private(set) array $statements = [];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly RecordedSql $recordedSql)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->recordedSql) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly RecordedSql $recordedSql)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->recordedSql->record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->recordedSql->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->recordedSql->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function record(string $sql): void
    {
        $this->statements[] = $sql;
    }
}
