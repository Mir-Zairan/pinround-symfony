<?php

declare(strict_types=1);

namespace App\Ticketing\Application\LinkProject;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Domain\ProjectAlreadyLinked;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\Projects;

/**
 * Links a project that has no team to one of the organization's teams, which then owns it (FR-ORG-06). Its writes
 * share the caller's transaction, begun under $organizationId. Who may link is the voter's question, asked by the UI:
 * ProjectPermission::LINK on $team.
 */
final readonly class LinkProject
{
    public function __construct(private Projects $projects, private OrganizationTeams $organizationTeams)
    {
    }

    /**
     * @param string $projectId a string, as the UI may not build the Domain's ProjectId
     *
     * @throws ProjectTeamNotFound when the team is not a team of the organization; nothing is saved
     * @throws ProjectNotFound     when the organization has no project with that id, or the id is not a project id;
     *                             nothing is saved
     * @throws ProjectStillLinked  when the project is linked to a team, $team included; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, TeamId $team, string $projectId): void
    {
        $this->organizationTeams->assertTeamOf($organizationId, $team);
        try {
            $project = $this->projects->byId(new ProjectId($projectId));
        } catch (\InvalidArgumentException) {
            $project = null;
        }
        if (null === $project || !$project->organizationId()->equals($organizationId)) {
            throw new ProjectNotFound('That project is not in this organization.');
        }

        try {
            $project->linkTo($team);
        } catch (ProjectAlreadyLinked $alreadyLinked) {
            throw new ProjectStillLinked('That project is already linked to a team.', 0, $alreadyLinked);
        }
        $this->projects->save($project);
    }
}
