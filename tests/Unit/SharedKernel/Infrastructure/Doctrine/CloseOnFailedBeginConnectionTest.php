<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CloseOnFailedBeginConnectionTest extends TestCase
{
    private int $driverConnects = 0;

    private ?\RuntimeException $beginFailure = null;

    private ?\RuntimeException $savepointFailure = null;

    #[Test]
    public function whenTheOutermostBeginFailsItClosesAndRethrowsSoTheNextBeginStartsOnANewConnection(): void
    {
        $connection = $this->connection();
        $this->beginFailure = new \RuntimeException('begin failed');

        self::assertSame($this->beginFailure, $this->failureOf($connection->beginTransaction(...)));
        self::assertFalse($connection->isTransactionActive());

        $this->beginFailure = null;
        $connection->beginTransaction();

        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(2, $this->driverConnects);
    }

    #[Test]
    public function whenASavepointFailsItKeepsTheConnectionAndItsOuterTransaction(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->savepointFailure = new \RuntimeException('savepoint failed');

        self::assertSame($this->savepointFailure, $this->failureOf($connection->beginTransaction(...)));
        self::assertTrue($connection->isTransactionActive());
        self::assertSame(1, $this->driverConnects);
        // DBAL counts the savepoint that never started; pinned so a DBAL change that fixes it is noticed.
        self::assertSame(2, $connection->getTransactionNestingLevel());
    }

    private function connection(): CloseOnFailedBeginConnection
    {
        $driverConnection = self::createStub(DriverConnection::class);
        $driverConnection->method('beginTransaction')->willReturnCallback(function (): void {
            if (null !== $this->beginFailure) {
                throw $this->beginFailure;
            }
        });
        $driverConnection->method('exec')->willReturnCallback(function (string $sql): int {
            if (null !== $this->savepointFailure && str_starts_with($sql, 'SAVEPOINT')) {
                throw $this->savepointFailure;
            }

            return 0;
        });

        $driver = self::createStub(Driver::class);
        $driver->method('connect')->willReturnCallback(function () use ($driverConnection): DriverConnection {
            ++$this->driverConnects;

            return $driverConnection;
        });
        $driver->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        return new CloseOnFailedBeginConnection([], $driver);
    }

    /**
     * @param \Closure(): void $action
     */
    private function failureOf(\Closure $action): ?\Throwable
    {
        try {
            $action();
        } catch (\RuntimeException $exception) {
            return $exception;
        }

        return null;
    }
}
