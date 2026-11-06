<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\ParameterType;

/**
 * @internal built by TenantDriver; the contract is on TenantMiddleware
 */
final class TenantConnection extends AbstractConnectionMiddleware
{
    // Third argument true = transaction-local. Never false, and never a plain SET (ADR-0005).
    private const string SET_TENANT_FOR_TRANSACTION = "SELECT set_config('app.org_id', ?, true)";

    public function __construct(Connection $wrappedConnection, private readonly TenantContext $tenantContext)
    {
        parent::__construct($wrappedConnection);
    }

    /**
     * DBAL calls this only for the outermost transaction; nested levels become savepoints without it.
     * When setting the tenant fails, the transaction is rolled back so the connection is not left in an
     * aborted one, and the original failure is rethrown.
     */
    public function beginTransaction(): void
    {
        parent::beginTransaction();

        $organizationId = $this->tenantContext->get();
        if (null === $organizationId) {
            return;
        }

        try {
            $statement = $this->prepare(self::SET_TENANT_FOR_TRANSACTION);
            $statement->bindValue(1, $organizationId->toString(), ParameterType::STRING);
            $statement->execute();
        } catch (\Throwable $setTenantFailure) {
            $this->rollBackQuietly();

            throw $setTenantFailure;
        }
    }

    // A failing rollback is dropped: the set_config failure is the cause the caller must see, PHP cannot
    // attach a second cause to an exception that already exists, and a broken connection fails again on next use.
    private function rollBackQuietly(): void
    {
        try {
            $this->rollBack();
        } catch (\Throwable) {
        }
    }
}
