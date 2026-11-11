<?php

declare(strict_types=1);

namespace App\Ticketing\Api\Event;

/**
 * An event of the Ticketing module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, and no event carries a
 * title, description or other ticket text (ADR-0027).
 */
interface TicketEvent
{
    public string $organizationId { get; }

    public string $ticketId { get; }
}
