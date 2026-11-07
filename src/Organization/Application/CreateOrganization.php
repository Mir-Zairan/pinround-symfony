<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\MemberJoined;
use App\Organization\Domain\BlankOrganizationName;
use App\Organization\Domain\InvalidOrganizationSlug;
use App\Organization\Domain\Membership;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Organizations;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Creates an organization with its first admin, who is also the triager of tickets with no team in the new settings
 * (FR-ORG-01). Records MemberJoined. Its writes share the caller's transaction, which must have begun with
 * $organizationId as the tenant: row-level security refuses the rows otherwise.
 */
final readonly class CreateOrganization
{
    public function __construct(
        private Organizations $organizations,
        private Memberships $memberships,
        private OrgSettingsStore $settings,
        private OrganizationEvents $events,
    ) {
    }

    /**
     * @param OrganizationId $organizationId a new id, from Organizations::nextIdentity()
     * @param ?UserId        $actingUser     null when the system creates it, as the demo seed does
     *
     * @throws BlankOrganizationName
     * @throws InvalidOrganizationSlug
     */
    public function __invoke(OrganizationId $organizationId, string $name, string $slug, UserId $firstAdmin, ?UserId $actingUser, \DateTimeImmutable $now): void
    {
        $this->organizations->add(new Organization($organizationId, $name, $slug, $now));
        $adminRoles = new OrgRoles(OrgRole::Admin);
        $this->memberships->add(new Membership($organizationId, $firstAdmin, $adminRoles, $now));
        $this->settings->add(new OrgSettings($organizationId, $firstAdmin, $now));

        $this->events->publish(new MemberJoined($organizationId->toString(), $firstAdmin->toString(), $adminRoles->values(), $actingUser?->toString(), $now));
    }
}
