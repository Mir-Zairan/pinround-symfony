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

        return $this->link($organizationId, $team, $projectId);
    }

    /**
     * Links every project to the team, checking the team once. The rows are locked in ascending id order whatever the
     * order of $projectIds, so two concurrent calls cannot wait on each other. Where one project fails, the ones
     * before it are already saved: the caller's transaction must be rolled back.
     *
     * @param list<string> $projectIds distinct, lower-case ids; the UI may not build the Domain's ProjectId
     *
     * @return array<string, string> the key of each linked project by its id, in ascending id order
     *
     * @throws ProjectTeamNotFound when the team is not a team of the organization; nothing is saved
     * @throws ProjectNotFound     when the organization has no project with one of the ids, or an id is not a project id
     * @throws ProjectStillLinked  when one of the projects is linked to a team
     * @throws \LogicException     when no transaction is open
     */
    public function linkAll(OrganizationId $organizationId, TeamId $team, array $projectIds): array
    {
        $this->organizationTeams->assertTeamOf($organizationId, $team);
        sort($projectIds);
        $keysById = [];
        foreach ($projectIds as $projectId) {
            $keysById[$projectId] = $this->link($organizationId, $team, $projectId);
        }

        return $keysById;
    }

    private function link(OrganizationId $organizationId, TeamId $team, string $projectId): string
    {
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
