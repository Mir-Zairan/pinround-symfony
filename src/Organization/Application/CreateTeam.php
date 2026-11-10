<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\TeamCreated;
use App\Organization\Domain\BlankTeamName;
use App\Organization\Domain\Job;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\Team;
use App\Organization\Domain\Teams;
use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * Creates a team with its lead, a member of the organization (FR-ORG-02), and the first slug made from its name that
 * the organization's other teams leave free. Records TeamCreated. Its writes share the caller's transaction, begun
 * under $organizationId.
 */
final readonly class CreateTeam
{
    public function __construct(private Teams $teams, private Memberships $memberships, private OrganizationEvents $events)
    {
    }

    /**
     * @param ?UserId $actingUser null when the system creates the team, as the demo seed does
     *
     * @throws BlankTeamName
     * @throws NotAnOrganizationMember when the lead is not a member of the organization; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, string $name, UserId $lead, Job $leadJob, ?UserId $actingUser, \DateTimeImmutable $now): TeamId
    {
        if (null === $this->memberships->of($organizationId, $lead)) {
            throw new NotAnOrganizationMember('Only a member of the organization can join a team.');
        }

        $base = TeamSlug::fromName($name);
        $slug = $base->firstFreeAmong($this->teams->slugsTakenFrom($organizationId, $base));
        $team = new Team($this->teams->nextIdentity(), $organizationId, $name, $slug, $lead, $leadJob, $now);
        $this->teams->add($team);

        $this->events->publish(new TeamCreated($organizationId->toString(), $team->id()->toString(), $lead->toString(), $actingUser?->toString(), $now));

        return $team->id();
    }
}
