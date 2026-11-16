<?php

declare(strict_types=1);

namespace App\Review\Api\Event;

/**
 * An event of the Review module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, and no event carries the
 * text of a note (ADR-0027): a consumer that needs it reads it through the Api.
 */
interface ReviewEvent
{
    public string $organizationId { get; }

    public string $ticketId { get; }

    /** The person who gave, undid or saved the verdict. */
    public string $actorId { get; }

    public \DateTimeImmutable $occurredAt { get; }
}
