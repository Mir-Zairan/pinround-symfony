<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * A team was created with its lead, its only member so far.
 */
final readonly class TeamCreated implements OrganizationEvent
{
    public function __construct(
        public string $organizationId,
        public string $teamId,
        public string $leadUserId,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
