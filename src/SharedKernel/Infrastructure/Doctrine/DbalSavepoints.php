<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\Savepoints;
use Doctrine\DBAL\Connection;

/**
 * Savepoints with DBAL's nested transactions, which are savepoints. Not the entity manager's: a failure inside its
 * wrapInTransaction would close it for the rest of the request.
 */
final readonly class DbalSavepoints implements Savepoints
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(\Closure $write): mixed
    {
        // Outside a transaction DBAL would open and commit one of its own, under no tenant (TenantConnection).
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A savepoint is part of an open transaction; none is open.');
        }

        return $this->connection->transactional(static fn (): mixed => $write());
    }
}
