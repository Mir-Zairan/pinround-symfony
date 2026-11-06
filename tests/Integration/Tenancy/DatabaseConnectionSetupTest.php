<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every DBAL connection in config/packages/doctrine.yaml gets the same class and PDO options.
 */
final class DatabaseConnectionSetupTest extends KernelTestCase
{
    #[Test]
    public function theTestEnvironmentHasTheAppAndTheMigratorConnection(): void
    {
        self::assertEqualsCanonicalizing(['default', 'migrator'], array_keys($this->doctrine()->getConnectionNames()));
    }

    #[Test]
    public function everyConnectionClosesItselfWhenATransactionCannotBegin(): void
    {
        foreach ($this->doctrine()->getConnections() as $name => $connection) {
            self::assertInstanceOf(CloseOnFailedBeginConnection::class, $connection, $name);
        }
    }

    #[Test]
    public function everyConnectionSendsBoundValuesWithoutServerSidePreparedStatements(): void
    {
        foreach ($this->doctrine()->getConnections() as $name => $connection) {
            self::assertInstanceOf(Connection::class, $connection);
            $pdo = $connection->getNativeConnection();
            self::assertInstanceOf(\PDO::class, $pdo);

            $statement = $pdo->prepare('SELECT ?::text');
            $statement->bindValue(1, 'bound');
            $statement->execute();

            self::assertSame('bound', $statement->fetchColumn(), $name);
            $preparedStatements = $pdo->query('SELECT count(*) FROM pg_prepared_statements');
            self::assertNotFalse($preparedStatements);
            self::assertSame(0, $preparedStatements->fetchColumn(), $name);
        }
    }

    private function doctrine(): ManagerRegistry
    {
        return self::getContainer()->get('doctrine');
    }
}
