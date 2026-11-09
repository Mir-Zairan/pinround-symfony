<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Application\OrganizationTransactions;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineOrganizationTransactions implements OrganizationTransactions
{
    public function __construct(
        private TenantSwitchingReads $tenantSwitchingReads,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function runAs(OrganizationId $organizationId, \Closure $write): mixed
    {
        $this->tenantSwitchingReads->refuseInsideATransaction();
        $tenantBefore = $this->tenantContext->get();
        // The switch guard refuses while the entity manager holds an entity such as the logged-in user.
        $this->entityManager->clear();
        try {
            $this->tenantContext->set($organizationId);

            return $this->entityManager->wrapInTransaction($write);
        } finally {
            // Entities loaded under $organizationId must not outlive its tenant.
            $this->entityManager->clear();
            null === $tenantBefore ? $this->tenantContext->clear() : $this->tenantContext->set($tenantBefore);
        }
    }
}
