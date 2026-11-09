<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Application\OrganizationNames;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;

final readonly class DbalOrganizationNames implements OrganizationNames
{
    private const string NAME_OF = 'SELECT name FROM org_organization WHERE organization_id = ?';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TenantSwitchingReads $tenantSwitchingReads,
    ) {
    }

    public function namesOf(array $organizationIds): array
    {
        $this->tenantSwitchingReads->refuseInsideATransaction();
        $names = [];
        foreach ($organizationIds as $organizationId) {
            $name = $this->tenantSwitchingReads->readAs(
                $organizationId,
                static fn (Connection $connection): mixed => $connection->fetchOne(self::NAME_OF, [$organizationId->toString()]),
            );
            if (\is_string($name)) {
                $names[$organizationId->toString()] = $name;
            }
        }

        return $names;
    }

    public function nameOfTenant(): ?string
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant || !$this->connection->isTransactionActive()) {
            return null;
        }

        $name = $this->connection->fetchOne(self::NAME_OF, [$tenant->toString()]);

        return \is_string($name) ? $name : null;
    }
}
