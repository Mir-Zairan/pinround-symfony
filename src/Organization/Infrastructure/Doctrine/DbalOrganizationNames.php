<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Application\OrganizationNames;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalOrganizationNames implements OrganizationNames
{
    private const string NAME_OF = 'SELECT name FROM org_organization WHERE organization_id = ?';

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
    ) {
    }

    public function namesOf(array $organizationIds): array
    {
        // A transaction already open would keep its own tenant (or none) for every read below.
        if ($this->connection->isTransactionActive()) {
            throw new TenantSwitchRefused();
        }
        $tenantBefore = $this->tenantContext->get();
        // The switch guard refuses a switch while the entity manager holds an entity, such as the logged-in user.
        $this->entityManager->clear();
        $names = [];
        try {
            foreach ($organizationIds as $organizationId) {
                $this->tenantContext->set($organizationId);
                $name = $this->connection->transactional(
                    static fn (Connection $connection): mixed => $connection->fetchOne(self::NAME_OF, [$organizationId->toString()]),
                );
                if (\is_string($name)) {
                    $names[$organizationId->toString()] = $name;
                }
            }
        } finally {
            null === $tenantBefore ? $this->tenantContext->clear() : $this->tenantContext->set($tenantBefore);
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
