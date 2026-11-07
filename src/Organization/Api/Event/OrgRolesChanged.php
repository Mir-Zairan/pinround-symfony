<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * A member's org roles changed. Recorded only when before and after differ.
 */
final readonly class OrgRolesChanged implements OrganizationEvent
{
    /**
     * @param list<string> $orgRolesBefore OrgRole values ("admin", "manager")
     * @param list<string> $orgRolesAfter  OrgRole values ("admin", "manager")
     */
    public function __construct(
        public string $organizationId,
        public string $userId,
        public array $orgRolesBefore,
        public array $orgRolesAfter,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
