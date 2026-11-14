<?php

declare(strict_types=1);

namespace App\Notification\Application\Mute;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Mutes one ticket for one person: NotifyRecipients writes them no notification about it, and it stays in their lists.
 * The caller decided MutePermission MUTE on the ticket first. Runs in the request transaction under the tenant.
 */
final readonly class MuteTicket
{
    public function __construct(private Mutes $mutes)
    {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function mute(UserId $userId, TicketId $ticketId, \DateTimeImmutable $now): void
    {
        $this->mutes->mute($userId, $ticketId, $now);
    }
}
