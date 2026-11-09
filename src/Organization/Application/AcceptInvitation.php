<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefused;
use App\Identity\Api\UserQueries;
use App\Organization\Domain\AlreadyAnOrganizationMember;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Invitations;
use App\Organization\Domain\Job;
use App\SharedKernel\Domain\UserId;

/**
 * Uses up an invitation (FR-ID-02): the person joins the organization with the invitation's org roles, and the team
 * with its team role when it names one. The invitation's row stays locked until the caller's transaction ends, so a
 * link used twice at once joins the person once. Its writes share the caller's transaction, begun under the link's
 * organization; on any exception the caller rolls it back.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private Invitations $invitations,
        private UserQueries $userQueries,
        private InvitedPersonRegistration $registration,
        private AddMember $addMember,
        private SetTeamRole $setTeamRole,
    ) {
    }

    /**
     * For a person who has an account: it must be the account with the invited email.
     *
     * @throws InvitationNoLongerValid     when the invitation is accepted, expired or gone at $now
     * @throws InvitationForAnotherPerson  when $userId is not the account with the invited email
     * @throws AlreadyAnOrganizationMember when the person joined the organization since the invitation was sent
     * @throws \LogicException             when no transaction is open
     */
    public function acceptAs(OpenInvitationLink $link, UserId $userId, \DateTimeImmutable $now): void
    {
        $invitation = $this->lockOpenInvitation($link, $now);
        $invitedAccountId = $this->userQueries->idByEmail($invitation->email());
        if (null === $invitedAccountId || !$invitedAccountId->equals($userId)) {
            throw new InvitationForAnotherPerson('This invitation is for another email address.');
        }

        $this->join($invitation, $userId, $now);
    }

    /**
     * For a person without an account: creates it with the invited email, then joins them.
     *
     * @return UserId the new account
     *
     * @throws InvitationNoLongerValid when the invitation is accepted, expired or gone at $now
     * @throws RegistrationRefused     when the name or password is refused, or an account has the invited email; with
     *                                 EmailTaken from a concurrent registration the EntityManager is closed (see
     *                                 InvitedPersonRegistration::register)
     * @throws \LogicException         when no transaction is open
     */
    public function acceptAsNewPerson(OpenInvitationLink $link, string $name, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): UserId
    {
        $invitation = $this->lockOpenInvitation($link, $now);
        $userId = $this->registration->register($invitation->email(), $name, $password, $now);

        $this->join($invitation, $userId, $now);

        return $userId;
    }

    private function lockOpenInvitation(OpenInvitationLink $link, \DateTimeImmutable $now): Invitation
    {
        $invitation = $this->invitations->lockById(new InvitationId($link->invitationId));
        if (null === $invitation || !$invitation->isOpenAt($now)) {
            throw new InvitationNoLongerValid('This invitation link no longer works.');
        }

        return $invitation;
    }

    private function join(Invitation $invitation, UserId $userId, \DateTimeImmutable $now): void
    {
        $invitation->accept($now);
        $this->invitations->save($invitation);

        ($this->addMember)($invitation->organizationId(), $userId, $invitation->orgRoles(), $userId, $now);
        $teamId = $invitation->teamId();
        $teamRole = $invitation->teamRole();
        if (null !== $teamId && null !== $teamRole) {
            // An invitation carries no job (FR-ORG-03), so Other until someone sets it on People and roles.
            ($this->setTeamRole)($teamId, $userId, $teamRole, Job::Other, $userId, $now);
        }
    }
}
