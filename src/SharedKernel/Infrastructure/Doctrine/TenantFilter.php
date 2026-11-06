<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Adds `<alias>.organization_id = '<org id>'` to every ORM query on a TenantOwned entity, on top of row-level
 * security (ADR-0005). The organization is the one of the current transaction, the same one RLS uses:
 * TenantFilterMiddleware sets it at each outermost BEGIN and removes it at COMMIT and ROLLBACK. With no
 * organization, which includes any query outside a transaction, a query on a TenantOwned entity throws
 * TenantNotSet. Other entities are not touched.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant';

    // The id goes in as a filter parameter, because Doctrine keys its DQL query cache and the persisters'
    // cached SQL on the filter parameters: SQL built for one organization is never reused for another.
    private const string ORGANIZATION_ID_PARAMETER = 'organization_id';

    private ?OrganizationId $organizationId = null;

    public function useOrganization(?OrganizationId $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->setParameter(self::ORGANIZATION_ID_PARAMETER, $organizationId?->toString() ?? '');
    }

    /**
     * @throws TenantNotSet
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_a($targetEntity->getName(), TenantOwned::class, true)) {
            return '';
        }

        if (null === $this->organizationId) {
            throw new TenantNotSet();
        }

        return $targetTableAlias.'.organization_id = '.$this->getParameter(self::ORGANIZATION_ID_PARAMETER);
    }
}
