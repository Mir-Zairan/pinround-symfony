<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * The attribute for pinging a person about one ticket; the subject is the ticket's Ticketing\Api\TicketVisibilityFacts.
 */
final class PingPermission
{
    public const string SEND = 'PING_SEND';
}
