<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\DatabaseAnswers;
use Doctrine\DBAL\Connection;
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

        self::assertLessThan(3.0, microtime(true) - $startedAt);
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
