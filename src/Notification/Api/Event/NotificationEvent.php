<?php

declare(strict_types=1);

namespace App\Notification\Api\Event;

/**
 * An event of the Notification module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, and no event carries the
 * text of a ping (ADR-0027).
 */
interface NotificationEvent
{
    public string $organizationId { get; }

    public string $actorId { get; }

    public \DateTimeImmutable $occurredAt { get; }
}
