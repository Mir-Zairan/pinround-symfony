<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

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
     * @param \Closure(): Connection             $connection    a closure: the connection's middleware needs TenantContext
     * @param \Closure(): EntityManagerInterface $entityManager a closure, for the same reason
     */
    public function __construct(
        #[AutowireServiceClosure('doctrine.dbal.default_connection')]
        private \Closure $connection,
        #[AutowireServiceClosure('doctrine.orm.default_entity_manager')]
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
        // An entity manager that is not built yet holds nothing, and reading it must not build it.
        if (new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager)) {
            return false;
        }

        $unitOfWork = $entityManager->getUnitOfWork();

        return $unitOfWork->size() > 0
            || [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityDeletions();
    }
}
