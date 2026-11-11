<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateProject;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Application\ProjectTeamNotFound;
use App\Ticketing\Domain\BlankProjectName;
use App\Ticketing\Domain\InvalidProjectKey;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use App\Ticketing\Domain\Projects;

/**
 * Creates a project owned by one of the organization's teams (FR-ORG-06). Its writes share the caller's transaction,
 * begun under $organizationId.
 */
final readonly class CreateProject
{
    public function __construct(private Projects $projects, private OrganizationTeams $organizationTeams)
    {
    }

    /**
     * @throws ProjectKeyNotValid  when the key does not follow the format; nothing is saved
     * @throws ProjectNameMissing  when nothing is left of the name after trimming; nothing is saved
     * @throws ProjectTeamNotFound when the owning team is not a team of the organization; nothing is saved
     * @throws ProjectKeyTaken     when another project of the organization has the key. When another transaction saved
     *                             it after the check here, the transaction is failed and the EntityManager closed: the
     *                             caller must roll back and must not use the ORM again in that request
     */
    public function __invoke(OrganizationId $organizationId, TeamId $owningTeam, string $key, string $name): ProjectId
    {
        try {
            $projectKey = new ProjectKey($key);
        } catch (InvalidProjectKey $invalidKey) {
            throw new ProjectKeyNotValid($invalidKey->getMessage(), 0, $invalidKey);
        }
        $this->organizationTeams->assertTeamOf($organizationId, $owningTeam);
        if (null !== $this->projects->byKey($projectKey)) {
            throw self::keyTaken($projectKey);
        }

        try {
            $project = new Project($this->projects->nextIdentity(), $organizationId, $owningTeam, $projectKey, $name);
        } catch (BlankProjectName $blankName) {
            throw new ProjectNameMissing($blankName->getMessage(), 0, $blankName);
        }
        try {
            $this->projects->add($project);
        } catch (ProjectKeyInUse $keyInUse) {
            throw self::keyTaken($projectKey, $keyInUse);
        }

        return $project->id();
    }

    private static function keyTaken(ProjectKey $key, ?ProjectKeyInUse $previous = null): ProjectKeyTaken
    {
        return new ProjectKeyTaken(\sprintf('Another project already uses the key %s.', $key->toString()), 0, $previous);
    }
}
