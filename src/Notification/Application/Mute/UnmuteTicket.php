<?php

declare(strict_types=1);

namespace App\Notification\Application\Mute;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Ends a person's mute of one ticket, so they get its notifications again; nothing changes for a ticket they had not
 * muted. The caller decided MutePermission MUTE on the ticket first. Runs in the request transaction under the tenant.
 */
final readonly class UnmuteTicket
{
    public function __construct(private Mutes $mutes)
    {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function unmute(UserId $userId, TicketId $ticketId): void
    {
        $this->mutes->unmute($userId, $ticketId);
    }
}
