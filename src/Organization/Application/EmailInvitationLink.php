<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The command to email the link of an invitation, queued for worker-notify when MemberInvited arrives
 * (QueueInvitationLinkEmailHandler); SendInvitationLink decides in the worker. It carries ids only: the link token is
 * made in the worker and never enters a queue.
 */
final readonly class EmailInvitationLink
{
    public function __construct(public string $organizationId, public string $invitationId)
    {
    }
}
