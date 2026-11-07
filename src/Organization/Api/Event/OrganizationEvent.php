<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * An event of the Organization module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings and roles are their stored
 * values, so a consumer needs no Organization class to read one.
 */
interface OrganizationEvent
{
    public string $organizationId { get; }

    /** The user who made the change; null when the system did, as the demo seed does. */
    public ?string $actorId { get; }

    public \DateTimeImmutable $occurredAt { get; }
}
