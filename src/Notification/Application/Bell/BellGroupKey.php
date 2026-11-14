<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One group of a person's bell: their notifications from one sender about one ticket.
 */
final readonly class BellGroupKey
{
    public function __construct(public UserId $recipientId, public UserId $senderId, public TicketId $ticketId)
    {
    }
}
