<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Application\EmailInvitationLink;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the invitation email of a MemberInvited as its own message, so it is sent in a transaction of its own: the
 * handlers of a received event share one (messenger.yaml › event.bus), and an email sent inside it stays sent when
 * another handler fails and rolls back the link token. It sends to async_notify at once and writes nothing to the
 * database. A retried or redelivered event queues a second message; SendInvitationLink sends one link per invitation
 * all the same.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class QueueInvitationLinkEmailHandler
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(MemberInvited $memberInvited): void
    {
        $this->commandBus->dispatch(new EmailInvitationLink($memberInvited->organizationId, $memberInvited->invitationId));
    }
}
