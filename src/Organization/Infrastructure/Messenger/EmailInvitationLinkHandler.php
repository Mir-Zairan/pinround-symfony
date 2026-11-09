<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Application\SendInvitationLink;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs in worker-notify on event.bus, inside the bus's transaction (doctrine_transaction), under the event's
 * organization from its TenantStamp; the outbox relay brought the event there (messenger.yaml).
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class EmailInvitationLinkHandler
{
    public function __construct(private SendInvitationLink $sendInvitationLink, private ClockInterface $clock)
    {
    }

    public function __invoke(MemberInvited $memberInvited): void
    {
        ($this->sendInvitationLink)($memberInvited, $this->clock->now());
    }
}
