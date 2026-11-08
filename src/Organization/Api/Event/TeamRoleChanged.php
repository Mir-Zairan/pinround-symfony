<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * A person's team role in one team changed, or they joined the team. A new lead records two: the new lead's and the
 * old lead's, who becomes a member.
 */
final readonly class TeamRoleChanged implements OrganizationEvent
{
    /**
     * @param ?string $teamRoleBefore a TeamRole value ("lead", "member", "viewer"); null when the person just joined
     * @param string  $teamRoleAfter  a TeamRole value
     */
    public function __construct(
        public string $organizationId,
        public string $teamId,
        public string $userId,
        public ?string $teamRoleBefore,
        public string $teamRoleAfter,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
