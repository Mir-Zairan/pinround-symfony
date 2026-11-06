<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Clears the entity manager when the organization changes, so find() never returns an object of the old
 * organization from the identity map without asking the database. Unsaved changes are never cleared away: with
 * any pending insert, update or delete the switch is refused. Entities of the old organization become detached.
 */
final readonly class EntityManagerTenantSwitchGuard implements TenantSwitchGuard
{
    /**
     * @param \Closure(): EntityManagerInterface $entityManager a closure, because the entity manager's connection needs TenantContext
     */
    public function __construct(
        #[AutowireServiceClosure('doctrine.orm.default_entity_manager')]
        private \Closure $entityManager,
    ) {
    }

    public function prepareSwitch(): void
    {
        $entityManager = ($this->entityManager)();
        // An entity manager that is not built yet holds nothing to clear.
        if (new \ReflectionClass($entityManager)->isUninitializedLazyObject($entityManager)) {
            return;
        }

        if ($this->hasUnsavedChanges($entityManager)) {
            throw new TenantSwitchRefused();
        }

        $entityManager->clear();
    }

    private function hasUnsavedChanges(EntityManagerInterface $entityManager): bool
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        // Changes to loaded entities are only found by comparing them, as flush() does.
        $unitOfWork->computeChangeSets();

        return [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
            || [] !== $unitOfWork->getScheduledCollectionUpdates()
            || [] !== $unitOfWork->getScheduledCollectionDeletions();
    }
}
