<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * An admin invited an email address to the organization. The address is not in the event, so it never reaches the
 * outbox or the audit log; a consumer that needs it reads the invitation by its id.
 */
final readonly class MemberInvited implements OrganizationEvent
{
    public function __construct(
        public string $organizationId,
        public string $invitationId,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
