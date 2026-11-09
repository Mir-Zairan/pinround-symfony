<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Writes under an organization that is not the request's tenant (yet), such as the one an invite link joins.
 */
interface OrganizationTransactions
{
    /**
     * Runs $write in a transaction of its own under $organizationId, commits it, and puts the tenant back as it was.
     * Clears the entity manager before and after, so unsaved changes are lost and loaded entities are detached. When
     * $write throws, the transaction is rolled back, the entity manager is left closed and the exception is rethrown.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @param-immediately-invoked-callable $write
     *
     * @return T
     *
     * @throws TenantSwitchRefused when a transaction is open, as it would keep its own tenant for the writes
     */
    public function runAs(OrganizationId $organizationId, \Closure $write): mixed;
}
