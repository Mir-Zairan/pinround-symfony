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
    private const string SET_TENANT_FOR_TRANSACTION = "SELECT set_config('".TenantSetting::NAME."', ?, true)";

    public function __construct(Connection $wrappedConnection, private readonly TenantContext $tenantContext)
    {
        parent::__construct($wrappedConnection);
    }

    /**
     * DBAL calls this only for the outermost transaction; nested levels become savepoints without it.
     * A failure to set the tenant is thrown as is; CloseOnFailedBeginConnection then closes the connection,
     * which ends the open transaction on the server.
     */
    public function beginTransaction(): void
    {
        parent::beginTransaction();

        $organizationId = $this->tenantContext->get();
        if (null === $organizationId) {
            return;
        }

        $statement = $this->prepare(self::SET_TENANT_FOR_TRANSACTION);
        $statement->bindValue(1, $organizationId->toString(), ParameterType::STRING);
        $statement->execute();
    }
}
