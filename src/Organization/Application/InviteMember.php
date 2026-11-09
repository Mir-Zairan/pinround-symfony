<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Api\UserQueries;
use App\Organization\Api\Event\MemberInvited;
use App\Organization\Domain\AlreadyAnOrganizationMember;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationAlreadyOpen;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Invitations;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\Teams;
use App\Organization\Domain\UnknownTeam;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * Invites an email address to the organization with these org roles and optionally a team role (FR-ID-02), open for
 * Invitation::LIFETIME_HOURS from $now. A new invitation of the same email replaces the one not yet accepted. The
 * invitation has no link token yet: the email worker attaches it. Records MemberInvited. Its writes share the
 * caller's transaction, begun under $organizationId.
 */
final readonly class InviteMember
{
    public function __construct(
        private Invitations $invitations,
        private Memberships $memberships,
        private Teams $teams,
        private UserQueries $userQueries,
        private OrganizationEvents $events,
    ) {
    }

    /**
     * Every refusal but InvitationAlreadyOpen comes before anything is saved.
     *
     * @param TeamRole|null $teamRole Member or Viewer, given exactly when $teamId is
     *
     * @throws TeamHasOneLead              when $teamRole is Lead
     * @throws \InvalidArgumentException   when the email is blank, or only one of $teamId and $teamRole is given
     * @throws UnknownTeam                 when no team of the organization has $teamId
     * @throws AlreadyAnOrganizationMember when the email belongs to a member of the organization
     * @throws InvitationAlreadyOpen       when another invitation of the email was saved at the same time, as by a
     *                                     double click; nothing is recorded, the EntityManager is closed and the
     *                                     caller's transaction must be rolled back, with no more ORM use in that
     *                                     request
     * @throws \LogicException             when no transaction is open
     */
    public function __invoke(OrganizationId $organizationId, string $email, OrgRoles $orgRoles, ?TeamId $teamId, ?TeamRole $teamRole, UserId $actingUser, \DateTimeImmutable $now): InvitationId
    {
        $invitation = Invitation::issue($this->invitations->nextIdentity(), $organizationId, $email, $orgRoles, $teamId, $teamRole, $actingUser, $now);
        if (null !== $teamId) {
            $team = $this->teams->byId($teamId);
            if (null === $team || !$team->organizationId()->equals($organizationId)) {
                throw new UnknownTeam('No team of this organization has this id.');
            }
        }
        $userId = $this->userQueries->idByEmail($invitation->email());
        if (null !== $userId && null !== $this->memberships->of($organizationId, $userId)) {
            throw new AlreadyAnOrganizationMember('This person is in the organization already.');
        }

        $this->invitations->removeNotAcceptedFor($organizationId, $invitation->email());
        $this->invitations->add($invitation);

        $this->events->publish(new MemberInvited($organizationId->toString(), $invitation->id()->toString(), $actingUser->toString(), $now));

        return $invitation->id();
    }
}
