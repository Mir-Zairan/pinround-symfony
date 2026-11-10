<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\MembershipQueries;
use App\Organization\Application\OrganizationTransactions;
use App\Organization\Domain\Organizations;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Finds a demo organization by its slug among the organizations of its first admin, as SeedDemoOrganizations names
 * them: the slug alone cannot be read before the organization is the tenant.
 */
final readonly class FirstAdminDemoOrganizations implements DemoOrganizations
{
    public function __construct(
        private UserQueries $users,
        private MembershipQueries $membershipQueries,
        private Organizations $organizations,
        private OrganizationTransactions $organizationTransactions,
    ) {
    }

    public function idBySlug(string $slug): ?OrganizationId
    {
        $firstAdminEmail = SeedDemoOrganizations::firstAdminOf($slug);
        $firstAdmin = null === $firstAdminEmail ? null : $this->users->idByEmail($firstAdminEmail);
        if (null === $firstAdmin) {
            return null;
        }

        foreach ($this->membershipQueries->organizationsOf($firstAdmin) as $organizationId) {
            if ($this->slugOf($organizationId) === $slug) {
                return $organizationId;
            }
        }

        return null;
    }

    private function slugOf(OrganizationId $organizationId): ?string
    {
        return $this->organizationTransactions->runAs($organizationId, fn (): ?string => $this->organizations->byId($organizationId)?->slug());
    }
}
