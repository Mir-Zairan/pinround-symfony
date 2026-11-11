<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
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

        return $this->entityManager->wrapInTransaction($write);
    }
}
