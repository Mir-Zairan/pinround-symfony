<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * A second entity manager on its own PostgreSQL session, as a second request would have, which begins its transactions
 * under the same tenant as the app's. The caller closes its connection in tearDown.
 */
final readonly class SecondSession
{
    public const string LOCK_NOT_AVAILABLE = '55P03';

    private function __construct(public EntityManagerInterface $entityManager)
    {
    }

    public static function besideTheApp(EntityManagerInterface $appEntityManager, TenantContext $tenantContext): self
    {
        $connection = new CloseOnFailedBeginConnection(
            $appEntityManager->getConnection()->getParams(),
            new TenantMiddleware($tenantContext)->wrap(new PgSQLDriver()),
        );

        $eventManager = $appEntityManager->getEventManager();
        Assert::assertInstanceOf(EventManager::class, $eventManager);

        return new self(new EntityManager($connection, $appEntityManager->getConfiguration(), $eventManager));
    }

    /**
     * Runs $change in its own transaction of this session, which gives up after lock_timeout, as PHP cannot wait in
     * two places, and rolls it back.
     *
     * @param \Closure(): void $change
     *
     * @return string the SQLSTATE $change failed with
     */
    public function sqlStateOfFailedAttempt(\Closure $change): string
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("SET lock_timeout = '300ms'");
        $connection->beginTransaction();
        try {
            $change();
        } catch (DriverException $failure) {
            return (string) $failure->getSQLState();
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
            $connection->executeStatement('RESET lock_timeout');
        }

        Assert::fail('The change ran although the rows were locked.');
    }
}
