<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads under an organization other than the request's, for lookups made before the request has a tenant or across
 * the organizations of one person. Each read has its own short transaction; the tenant is put back as it was after it.
 */
final readonly class TenantSwitchingReads
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * A transaction already open would keep its own tenant (or none) for the reads, so they are refused.
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function refuseInsideATransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new TenantSwitchRefused();
        }
    }

    /**
     * Runs $read in a short transaction under $organizationId. Clears the entity manager first, as the switch guard
     * refuses a switch while it holds an entity such as the logged-in user; unsaved changes are lost.
     *
     * @template T
     *
     * @param \Closure(Connection): T $read
     *
     * @return T
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function readAs(OrganizationId $organizationId, \Closure $read): mixed
    {
        $this->refuseInsideATransaction();
        $tenantBefore = $this->tenantContext->get();
        $this->entityManager->clear();
        try {
            $this->tenantContext->set($organizationId);

            return $this->connection->transactional($read);
        } finally {
            null === $tenantBefore ? $this->tenantContext->clear() : $this->tenantContext->set($tenantBefore);
        }
    }
}
