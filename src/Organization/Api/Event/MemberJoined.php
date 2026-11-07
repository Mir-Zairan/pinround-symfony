<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * A user became a member of an organization, with these org roles.
 */
final readonly class MemberJoined implements OrganizationEvent
{
    /**
     * @param list<string> $orgRoles OrgRole values ("admin", "manager"); empty for a plain member
     */
    public function __construct(
        public string $organizationId,
        public string $userId,
        public array $orgRoles,
        public ?string $actorUserId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
