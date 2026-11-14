<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Why a ping left the tray: the person marked it done, or they acted on the ticket.
 */
enum PingClearedReason: string
{
    case Done = 'done';
    case Acted = 'acted';
}
