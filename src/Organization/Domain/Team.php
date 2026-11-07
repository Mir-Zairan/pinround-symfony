<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A team of an organization with its members. It always has exactly one lead, who is also one of its members with
 * the team role Lead; lead_user_id repeats that person for queries that need only the lead. Every team role change
 * goes through this class; members are handed out as TeamMemberView copies.
 */
final class Team implements TenantOwned
{
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $name;
    private string $leadUserId;
    // Not readonly: Teams::lockById refreshes a loaded team, and Doctrine then sets every field again, which a
    // readonly property refuses for a new but equal date object.
    private \DateTimeImmutable $createdAt;

    /**
     * Starts as a list and becomes Doctrine's collection once the team is saved or loaded; both take `[] =`.
     *
     * @var array<int, TeamMember>|(\ArrayAccess<int, TeamMember>&\Traversable<int, TeamMember>)
     */
    private array|(\ArrayAccess&\Traversable) $members;

    /**
     * @throws BlankTeamName when nothing is left of the name after trimming
     */
    public function __construct(TeamId $id, OrganizationId $organizationId, string $name, UserId $lead, Job $leadJob, \DateTimeImmutable $createdAt)
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new BlankTeamName('A team needs a name.');
        }

        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->name = $trimmedName;
        $this->leadUserId = $lead->toString();
        $this->createdAt = $createdAt;
        $this->members = [new TeamMember($this, $lead, TeamRole::Lead, $leadJob, $createdAt)];
    }

    public function id(): TeamId
    {
        return new TeamId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function leadUserId(): UserId
    {
        return new UserId($this->leadUserId);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return list<TeamMemberView> in the order they joined the team in this object; a loaded team's order is the
     *                              database's
     */
    public function members(): array
    {
        $views = [];
        foreach ($this->members as $member) {
            $views[] = $member->view();
        }

        return $views;
    }

    public function memberOf(UserId $userId): ?TeamMemberView
    {
        return $this->memberEntityOf($userId)?->view();
    }

    /**
     * @param TeamRole $teamRole Member or Viewer; a lead comes only through appointLead()
     *
     * @throws TeamHasOneLead     when $teamRole is Lead
     * @throws AlreadyATeamMember when the person is in the team already
     */
    public function addMember(UserId $userId, TeamRole $teamRole, Job $job, \DateTimeImmutable $addedAt): void
    {
        if (TeamRole::Lead === $teamRole) {
            throw new TeamHasOneLead('A team has exactly one lead. Appoint a member as the new lead instead.');
        }
        if (null !== $this->memberEntityOf($userId)) {
            throw new AlreadyATeamMember('This person is in the team already.');
        }

        $this->members[] = new TeamMember($this, $userId, $teamRole, $job, $addedAt);
    }

    /**
     * Makes a member of the team its lead and the old lead a Member, so the team keeps exactly one lead.
     *
     * @return list<TeamRoleChange> the new lead's change first, then the old lead's; none when $userId leads already
     *
     * @throws NotATeamMember when the person is not in the team
     */
    public function appointLead(UserId $userId): array
    {
        $newLead = $this->memberEntityOf($userId) ?? throw new NotATeamMember('Only a member of the team can become its lead.');
        if ($newLead->isLead()) {
            return [];
        }
        $oldLead = $this->memberEntityOf($this->leadUserId()) ?? throw new \LogicException('A team always has its lead among its members.');

        $changes = [new TeamRoleChange($userId, $newLead->teamRole(), TeamRole::Lead), new TeamRoleChange($this->leadUserId(), TeamRole::Lead, TeamRole::Member)];
        $newLead->changeTeamRole(TeamRole::Lead);
        $oldLead->changeTeamRole(TeamRole::Member);
        $this->leadUserId = $userId->toString();

        return $changes;
    }

    /**
     * Moves a person between Member and Viewer. The lead changes only through appointLead().
     *
     * @param TeamRole $teamRole Member or Viewer
     *
     * @return TeamRoleChange|null null when the person has that role already
     *
     * @throws TeamHasOneLead when $teamRole is Lead, or the person is the lead
     * @throws NotATeamMember when the person is not in the team
     */
    public function changeTeamRole(UserId $userId, TeamRole $teamRole): ?TeamRoleChange
    {
        $member = $this->memberEntityOf($userId) ?? throw new NotATeamMember('Only a member of the team can change role in it.');
        if (TeamRole::Lead === $teamRole) {
            throw new TeamHasOneLead('A team has exactly one lead. Appoint a member as the new lead instead.');
        }
        if ($member->isLead()) {
            throw new TeamHasOneLead('A team has exactly one lead. Appoint a new lead first.');
        }
        if ($member->teamRole() === $teamRole) {
            return null;
        }

        $change = new TeamRoleChange($userId, $member->teamRole(), $teamRole);
        $member->changeTeamRole($teamRole);

        return $change;
    }

    private function memberEntityOf(UserId $userId): ?TeamMember
    {
        foreach ($this->members as $member) {
            if ($member->isOf($userId)) {
                return $member;
            }
        }

        return null;
    }
}
