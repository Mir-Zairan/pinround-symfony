<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Organization\Application\EmailInvitationLink;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\OrganizationCommandsOnTheEventBus;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Mime\Email;

/**
 * Invites through InviteMember and SendInvitationLink, as worker-notify would, so the link is the one the mailer
 * builds. Use it in a WebTestCase.
 */
trait SendsInvitationLinks
{
    /**
     * @return string the emailed link
     */
    private function invite(OrganizationId $organizationId, string $email): string
    {
        $container = self::getContainer();
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        $tenantContext = $container->get(TenantContext::class);
        $commands = new OrganizationCommandsOnTheEventBus($entityManager, $container->get('event.bus'));
        // The lookup and the link email compare with the real time, so the invitation is made now.
        $invitedAt = $container->get(ClockInterface::class)->now();
        $entityManager->clear();
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            $invitationId = $entityManager->wrapInTransaction(static fn (): string => $commands->invite($organizationId->toString(), $email, $invitedAt));
            $entityManager->clear();
            $container->get('command.bus')->dispatch(new Envelope(new EmailInvitationLink($organizationId->toString(), $invitationId), [new ReceivedStamp('async_notify')]));
        } finally {
            $entityManager->clear();
            $tenantContext->reset();
        }

        $messages = self::getMailerMessages();
        $sentEmail = end($messages);
        self::assertInstanceOf(Email::class, $sentEmail);
        self::assertSame(1, preg_match('~https?://\S+/invite/[A-Za-z0-9_-]+~', (string) $sentEmail->getTextBody(), $match));

        return $match[0];
    }
}
