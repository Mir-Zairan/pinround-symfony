<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\InvitationLinkToken;
use App\Organization\Domain\Invitations;
use App\Organization\Domain\Organizations;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Handles a MemberInvited in worker-notify, under the event's organization: when the invitation is still there, open
 * at $now and has no link yet, it makes the link token, saves its hash on the invitation and emails the link. It runs
 * once per message (OnceOnlyHandling), and an invitation keeps its first link token, so neither a redelivered nor a
 * repeated event sends a second link, unless the email was sent and the transaction then failed to commit.
 */
final readonly class SendInvitationLink
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered events would send again. */
    public const string HANDLER_NAME = 'organization.send_invitation_link';

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private Invitations $invitations,
        private Organizations $organizations,
        private InvitationMailer $mailer,
    ) {
    }

    /**
     * @throws \LogicException when the organization of the invitation is not found
     */
    public function __invoke(MemberInvited $memberInvited, \DateTimeImmutable $now): void
    {
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($memberInvited, $now): void {
            // Missing when a re-invite replaced it; that one has its own MemberInvited.
            $invitation = $this->invitations->byId(new InvitationId($memberInvited->invitationId));
            if (null === $invitation || !$invitation->isOpenAt($now) || $invitation->hasLinkToken()) {
                return;
            }
            $organization = $this->organizations->byId(new OrganizationId($memberInvited->organizationId));
            if (null === $organization) {
                throw new \LogicException('The organization of the invitation is not found.');
            }

            $token = InvitationLinkToken::generate();
            $invitation->attachLinkTokenHash($token->hash());
            $this->invitations->add($invitation);
            // Last, so a failed send rolls the hash back and the next delivery tries again.
            $this->mailer->sendLink($invitation, $organization->name(), $token);
        });
    }
}
