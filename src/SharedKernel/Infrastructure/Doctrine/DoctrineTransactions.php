<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext, private ManagerRegistry $doctrine)
    {
    }

    public function run(\Closure $write): mixed
    {
        // TenantConnection sets the tenant at BEGIN from TenantContext: without one, row-level security shows no rows.
        if (null === $this->tenantContext->get()) {
            throw new \LogicException('A transaction of its own needs the request\'s tenant.');
        }
        // Inside an open transaction this would only be a savepoint: nothing is committed when run returns, and a
        // failure would close the request's EntityManager.
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('A transaction of its own must not run inside another transaction.');
        }

        // A refusal thrown out of an earlier run in the same request closed the EntityManager; resetting the lazy
        // service reopens the one every repository holds, so a read after a refused write still runs. Before the
        // BEGIN below, where TenantFilterMiddleware marks the rebuilt manager's tenant filter.
        if (!$this->entityManager->isOpen()) {
            $this->doctrine->resetManager();
        }

        return $this->entityManager->wrapInTransaction($write);
    }
}
