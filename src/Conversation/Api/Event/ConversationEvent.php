<?php

declare(strict_types=1);

namespace App\Conversation\Api\Event;

/**
 * An event of the Conversation module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, and no event carries the
 * text of a comment, question or reply (ADR-0027): a consumer that needs it reads it through the Api.
 */
interface ConversationEvent
{
    public string $organizationId { get; }

    public string $ticketId { get; }

    /** The person who posted, asked, answered, replied or resolved. */
    public string $actorId { get; }

    public \DateTimeImmutable $occurredAt { get; }
}
