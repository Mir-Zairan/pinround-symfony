<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The attribute for muting and unmuting one ticket for oneself; the subject is the ticket's
 * Ticketing\Api\TicketVisibilityFacts.
 */
final class MutePermission
{
    public const string MUTE = 'NOTIFICATION_MUTE';
}
