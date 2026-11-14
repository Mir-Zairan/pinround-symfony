<?php

declare(strict_types=1);

namespace App\Notification\Api\Event;

/**
 * A Notification event about one ping, which is always about one ticket.
 */
interface PingEvent extends NotificationEvent
{
    public string $pingId { get; }

    public string $ticketId { get; }
}
