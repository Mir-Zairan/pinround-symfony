<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Refuses an organization switch while anything of the current organization is still open: a transaction on the
 * default connection (it keeps the old organization for RLS and the filter until it ends), or any entity in the
 * entity manager (it could be returned to, or written for, the new organization). The caller ends the
 * transaction, flushes and clears the entity manager itself. This guard only reads; it never changes Doctrine
 * state, so a refused switch leaves the unit of work exactly as it was.
 */
final readonly class DoctrineTenantSwitchGuard implements TenantSwitchGuard
{
    /**
     * Both are taken from the registry, never as service references, not even lazy ones: the connection's
     * middleware needs TenantContext, which needs this guard, and any reference back to the connection makes the
     * dumped dev and prod containers create the connection before they give it its middlewares.
     *
     * @param \Closure(): Connection             $connection
     * @param \Closure(): EntityManagerInterface $entityManager
     */
    public function __construct(
        #[AutowireCallable(service: 'doctrine', method: 'getConnection')]
        private \Closure $connection,
        #[AutowireCallable(service: 'doctrine', method: 'getManager')]
        private \Closure $entityManager,
    ) {
    }

    public function prepareSwitch(): void
    {
        if (($this->connection)()->isTransactionActive() || $this->entityManagerHoldsEntities()) {
            throw new TenantSwitchRefused();
        }
    }

    private function entityManagerHoldsEntities(): bool
    {
        $entityManager = ($this->entityManager)();
        if (!LazyObjectState::isBuilt($entityManager)) {
            return false;
        }

        $unitOfWork = $entityManager->getUnitOfWork();

        return $unitOfWork->size() > 0
            || [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityDeletions();
    }
}
