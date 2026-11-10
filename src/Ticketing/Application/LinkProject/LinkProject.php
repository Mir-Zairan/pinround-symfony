<?php

declare(strict_types=1);

namespace App\Ticketing\Application\LinkProject;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Domain\ProjectAlreadyLinked;
use App\Ticketing\Domain\Projects;

/**
 * Links a project that has no team to one of the organization's teams, which then owns it (FR-ORG-06). It must run in
 * the caller's transaction, begun under $organizationId: the project's row stays locked until it ends, so a second
 * link of the same project waits and is then refused. Who may link is the voter's question, asked by the UI:
 * ProjectPermission::LINK on $team.
 */
final readonly class LinkProject
{
    public function __construct(
        private Projects $projects,
        private OrganizationProjects $organizationProjects,
        private OrganizationTeams $organizationTeams,
    ) {
    }

    /**
     * @param string $projectId a string, as the UI may not build the Domain's ProjectId
     *
     * @return string the key of the project that was linked
     *
     * @throws ProjectTeamNotFound when the team is not a team of the organization; nothing is saved
     * @throws ProjectNotFound     when the organization has no project with that id, or the id is not a project id;
     *                             nothing is saved
     * @throws ProjectStillLinked  when the project is linked to a team, $team included; nothing is saved
     * @throws \LogicException     when no transaction is open; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, TeamId $team, string $projectId): string
    {
        $this->organizationTeams->assertTeamOf($organizationId, $team);
        $project = $this->organizationProjects->lockedProjectOf($organizationId, $projectId);

        try {
            $project->linkTo($team);
        } catch (ProjectAlreadyLinked $alreadyLinked) {
            throw new ProjectStillLinked('That project is already linked to a team.', 0, $alreadyLinked);
        }
        $this->projects->save($project);

        return $project->key()->toString();
    }
}
