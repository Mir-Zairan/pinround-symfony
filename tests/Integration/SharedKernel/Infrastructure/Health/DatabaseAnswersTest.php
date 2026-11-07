<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\DatabaseAnswers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseAnswersTest extends KernelTestCase
{
    #[Test]
    public function itPassesWhenTheDefaultConnectionsDatabaseAnswersAndLeavesThatConnectionAlone(): void
    {
        $defaultConnection = $this->defaultConnection();
        $defaultConnection->close();

        new DatabaseAnswers($defaultConnection)->verify();

        self::assertFalse($defaultConnection->isConnected());
    }

    #[Test]
    public function itThrowsWhenNothingListens(): void
    {
        $this->expectException(ConnectionException::class);

        new DatabaseAnswers($this->connectionTo('127.0.0.1', 1))->verify();
    }

    #[Test]
    public function theProbeConnectsWithAOneSecondTimeoutAndKeepsTheOtherDriverOptions(): void
    {
        /** @var \ArrayObject<int, array<string, mixed>> $connectParams */
        $connectParams = new \ArrayObject();
        $recordsConnectParams = new class(new PgSQLDriver(), $connectParams) extends AbstractDriverMiddleware {
            /**
             * @param \ArrayObject<int, array<string, mixed>> $connectParams
             */
            public function __construct(Driver $driver, private readonly \ArrayObject $connectParams)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                $this->connectParams->append($params);

                return parent::connect($params);
            }
        };
        $defaultParams = $this->defaultConnection()->getParams();
        $defaultDriverOptions = $defaultParams['driverOptions'] ?? null;
        self::assertIsArray($defaultDriverOptions);
        self::assertNotSame([], $defaultDriverOptions, 'doctrine.yaml sets driver options the probe must keep');

        new DatabaseAnswers(new Connection($defaultParams, $recordsConnectParams))->verify();

        self::assertCount(1, $connectParams);
        self::assertSame([\PDO::ATTR_TIMEOUT => 1] + $defaultDriverOptions, $connectParams[0]['driverOptions'] ?? null);
    }

    #[Test]
    public function itGivesUpOnAHostThatNeverAnswersWithinTheConnectTimeout(): void
    {
        // TEST-NET-1 (RFC 5737): routed nowhere, so a connect waits for its timeout (pdo_pgsql's default is 30 s).
        $unanswered = new DatabaseAnswers($this->connectionTo('192.0.2.1', 5432));
        $startedAt = microtime(true);

        try {
            $unanswered->verify();
            self::fail('A host that never answers passed.');
        } catch (ConnectionException) {
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        if ($elapsedSeconds < 0.5) {
            self::markTestSkipped('This network refused 192.0.2.1 at once, so the connect timeout was not reached.');
        }
        self::assertLessThan(3.0, $elapsedSeconds);
    }

    private function connectionTo(string $host, int $port): Connection
    {
        return new Connection(['host' => $host, 'port' => $port] + $this->defaultConnection()->getParams(), new PgSQLDriver());
    }

    private function defaultConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
