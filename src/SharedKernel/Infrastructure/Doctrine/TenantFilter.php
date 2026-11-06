<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\TenantOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Restricts every ORM query on a TenantOwned entity to the organization of the current transaction, on top of
 * row-level security (ADR-0005), so it still holds for a role that bypasses RLS. TenantFilterMiddleware marks
 * at each outermost BEGIN whether that transaction has an organization, and clears the mark at COMMIT and
 * ROLLBACK. Without the mark, which includes any query outside a transaction, a query on a TenantOwned entity
 * throws TenantNotSet. Other entities are not touched.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant';

    // Doctrine keys its query cache on the filter parameters, so the only one is this yes/no mark: each query is
    // cached once, because compiling a TenantOwned query without the mark throws and caches nothing.
    private const string IS_TENANT_SET_PARAMETER = 'is_tenant_set';

    // Reads the setting RLS reads instead of a literal id, so the SQL is the same for every organization.
    private const string ORGANIZATION_OF_TRANSACTION = "NULLIF(current_setting('app.org_id', true), '')::uuid";

    private bool $isTenantSet = false;

    public function markTenantSet(bool $isTenantSet): void
    {
        $this->isTenantSet = $isTenantSet;
        $this->setParameter(self::IS_TENANT_SET_PARAMETER, $isTenantSet);
    }

    /**
     * @throws TenantNotSet
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_a($targetEntity->getName(), TenantOwned::class, true)) {
            return '';
        }

        if (!$this->isTenantSet) {
            throw new TenantNotSet();
        }

        return $targetTableAlias.'.organization_id = '.self::ORGANIZATION_OF_TRANSACTION;
    }
}
