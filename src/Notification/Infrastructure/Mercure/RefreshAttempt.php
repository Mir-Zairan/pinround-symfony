<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

/**
 * Which try of a ticket page refresh a RefreshTicketPages message is (realtime-and-notifications.md, Coalescing).
 */
enum RefreshAttempt: string
{
    /** Sent as the change arrives: it publishes when the ticket's window is free, else it queues one Trailing. */
    case Leading = 'leading';

    /** Sent one window later, once per window: it publishes when the window is free, else it queues one LastTrailing. */
    case Trailing = 'trailing';

    /**
     * The trailing try that waits one more window, because the window it met was taken by a refresh that may have
     * published before the last change. It publishes when the window is free, else drops: a newer refresh covers it.
     */
    case LastTrailing = 'last_trailing';
}
