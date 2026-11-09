<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Organization\Application\EmailInvitationLink;
use App\Organization\Application\SendInvitationLink;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs in worker-notify, inside the bus's transaction (doctrine_transaction), which no other handler shares, under the
 * invitation's organization from the message's TenantStamp.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class EmailInvitationLinkHandler
{
    public function __construct(private SendInvitationLink $sendInvitationLink, private ClockInterface $clock)
    {
    }

    public function __invoke(EmailInvitationLink $request): void
    {
        ($this->sendInvitationLink)($request, $this->clock->now());
    }
}
