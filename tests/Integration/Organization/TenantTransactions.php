<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs test work in one transaction of one organization, as a request would, and leaves no tenant, no transaction
 * and no loaded entity behind, so the next call may use another organization.
 */
final readonly class TenantTransactions
{
    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function committedAs(string $organizationId, \Closure $work): mixed
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            return $this->entityManager->wrapInTransaction(static fn (): mixed => $work());
        } finally {
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function rolledBackAs(string $organizationId, \Closure $work): mixed
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            return $work();
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }
    }
}
