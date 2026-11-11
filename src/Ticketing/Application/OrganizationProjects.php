<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\Projects;

/**
 * Finds a project of the organization by the id the UI sends, for a command that changes it.
 */
final readonly class OrganizationProjects
{
    public function __construct(private Projects $projects)
    {
    }

    /**
     * The project, its row locked until the caller's transaction ends (Projects::lockById), so a second change to it
     * waits and then checks what the first one committed.
     *
     * @param string $projectId a string, as the UI may not build the Domain's ProjectId
     *
     * @throws ProjectNotFound when the organization has no project with that id, or the id is not a project id
     * @throws \LogicException when no transaction is open
     */
    public function lockedProjectOf(OrganizationId $organizationId, string $projectId): Project
    {
        try {
            $id = new ProjectId($projectId);
        } catch (\InvalidArgumentException $notAProjectId) {
            throw new ProjectNotFound('That project is not in this organization.', 0, $notAProjectId);
        }
        $project = $this->projects->lockById($id);
        if (null === $project || !$project->organizationId()->equals($organizationId)) {
            throw new ProjectNotFound('That project is not in this organization.');
        }

        return $project;
    }
}
