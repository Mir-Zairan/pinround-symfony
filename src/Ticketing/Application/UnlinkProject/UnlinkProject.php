<?php

declare(strict_types=1);

namespace App\Ticketing\Application\UnlinkProject;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Domain\Projects;

/**
 * Unlinks a project from the team that owns it (FR-ORG-06); it has no team until LinkProject links it again, and its
 * tickets are kept. It must run in the caller's transaction, begun under $organizationId: the project's row stays
 * locked until it ends, so a link of the same project, or a ticket taking a number in it, waits for it. Who may unlink
 * is the voter's question, asked by the UI: ProjectPermission::UNLINK on $owningTeam. The project must then be that
 * team's, so a lead never unlinks another team's project.
 */
final readonly class UnlinkProject
{
    public function __construct(
        private Projects $projects,
        private OrganizationProjects $organizationProjects,
        private OpenTicketCounts $openTicketCounts,
    ) {
    }

    /**
     * @param string $projectId a string, as the UI may not build the Domain's ProjectId
     *
     * @throws ProjectNotFound       when the organization has no project with that id, or the id is not a project id;
     *                               nothing is saved
     * @throws ProjectNotOfTeam      when the project is not linked to $owningTeam, also when it is linked to no team;
     *                               nothing is saved
     * @throws ProjectHasOpenTickets when the project still has open tickets; nothing is saved
     * @throws \LogicException       when no transaction is open; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, TeamId $owningTeam, string $projectId): void
    {
        $project = $this->organizationProjects->lockedProjectOf($organizationId, $projectId);
        if (!($project->owningTeam()?->equals($owningTeam) ?? false)) {
            throw new ProjectNotOfTeam('That project is not linked to this team.');
        }
        $openTickets = $this->openTicketCounts->openTicketsIn($project->id())[$project->id()->toString()] ?? 0;
        if ($openTickets > 0) {
            throw new ProjectHasOpenTickets($openTickets);
        }

        $project->unlink();
        $this->projects->save($project);
    }
}
