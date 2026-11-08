<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Domain\Job;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\TeamHasOneLead;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\Teams;
use App\Organization\Domain\UnknownTeam;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * Gives a member of the organization this role in the team, adding them to the team first when they are not in it
 * (FR-ORG-02). A new lead turns the old lead into a Member, so the team keeps exactly one lead; the team's row stays
 * locked until the caller's transaction ends, so two lead changes of one team run one after the other. Records one
 * TeamRoleChanged per person whose role changed, the new lead's first.
 */
final readonly class SetTeamRole
{
    public function __construct(private Teams $teams, private Memberships $memberships, private OrganizationEvents $events)
    {
    }

    /**
     * @param Job     $job        the person's job when they join the team; a member keeps the job they have
     * @param ?UserId $actingUser null when the system changes the role, as the demo seed does
     *
     * @throws UnknownTeam             when no team of the transaction's organization has this id
     * @throws NotAnOrganizationMember when the user is not a member of the team's organization; nothing is saved
     * @throws TeamHasOneLead          when the lead would become a Member or Viewer; appoint another lead instead
     * @throws \LogicException         when no transaction is open
     */
    public function __invoke(TeamId $teamId, UserId $userId, TeamRole $teamRole, Job $job, ?UserId $actingUser, \DateTimeImmutable $now): void
    {
        $team = $this->teams->lockById($teamId) ?? throw new UnknownTeam('No team of this organization has this id.');
        if (null === $this->memberships->of($team->organizationId(), $userId)) {
            throw new NotAnOrganizationMember('Only a member of the organization can join a team.');
        }

        $isJoining = null === $team->memberOf($userId);
        if ($isJoining) {
            $team->addMember($userId, TeamRole::Lead === $teamRole ? TeamRole::Member : $teamRole, $job, $now);
        }
        $changes = TeamRole::Lead === $teamRole
            ? $team->appointLead($userId)
            : array_filter([$team->changeTeamRole($userId, $teamRole)]);
        if (!$isJoining && [] === $changes) {
            return;
        }
        $this->teams->save($team);

        $organizationId = $team->organizationId()->toString();
        $teamRoleChanged = static fn (UserId $changedUserId, ?TeamRole $before, TeamRole $after): TeamRoleChanged => new TeamRoleChanged(
            $organizationId,
            $teamId->toString(),
            $changedUserId->toString(),
            $before?->value,
            $after->value,
            $actingUser?->toString(),
            $now,
        );
        // Joining as a Member or Viewer changes no role afterwards, so only the joining itself is recorded.
        if ($isJoining && [] === $changes) {
            $this->events->publish($teamRoleChanged($userId, null, $teamRole));
        }
        foreach ($changes as $change) {
            $hasJustJoined = $isJoining && $change->userId->equals($userId);
            $this->events->publish($teamRoleChanged($change->userId, $hasJustJoined ? null : $change->from, $change->to));
        }
    }
}
