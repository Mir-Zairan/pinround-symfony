<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Application\TicketingTransactions;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTicketingTransactions implements TicketingTransactions
{
    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
    {
    }

    public function run(\Closure $write): mixed
    {
        // TenantConnection sets the tenant at BEGIN from TenantContext: without one, row-level security shows no rows.
        if (null === $this->tenantContext->get()) {
            throw new \LogicException('A Ticketing transaction needs the request\'s tenant.');
        }
        // A transaction already open would turn this one into a savepoint, whose rollback leaves a failed transaction.
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('A Ticketing transaction must not run inside another transaction.');
        }

        return $this->entityManager->wrapInTransaction($write);
    }
}
