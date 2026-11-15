<?php

declare(strict_types=1);

namespace App\Notification\Api\Event;

/**
 * Why a ping was cleared; the values are those of ntf_ping.cleared_reason.
 */
enum PingClearedReason: string
{
    /** The recipient acted on the ticket while auto-clear was on: a move, comment, reply, answer, verdict, check action or assignment. */
    case Acted = 'acted';
    /** The recipient clicked "Done". */
    case Done = 'done';
}
