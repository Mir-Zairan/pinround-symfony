<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;

/**
 * A project of an organization, owned by at most one of its teams (FR-ORG-06): it is created for a team, can be
 * unlinked from it and then linked to a team again. Its tickets are numbered from 1 up without gaps; the counter is
 * not kept on this object but handed out by NextTicketNumber, straight in the database.
 */
final class Project implements TenantOwned
{
    private readonly string $organizationId;
    private readonly string $id;
    /** The owning team; null while the project is unlinked. */
    private ?string $teamId;
    private readonly string $key;
    private readonly string $name;
    // The number the next ticket gets. Only NextTicketNumber changes it, in the database; this copy is the start.
    private readonly int $nextNumber;

    /**
     * @throws BlankProjectName when nothing is left of the name after trimming
     */
    public function __construct(ProjectId $id, OrganizationId $organizationId, TeamId $owningTeam, ProjectKey $key, string $name)
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new BlankProjectName('A project needs a name.');
        }

        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->teamId = $owningTeam->toString();
        $this->key = $key->toString();
        $this->name = $trimmedName;
        $this->nextNumber = 1;
    }

    public function id(): ProjectId
    {
        return new ProjectId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    /**
     * Null while the project is unlinked.
     */
    public function owningTeam(): ?TeamId
    {
        return null === $this->teamId ? null : new TeamId($this->teamId);
    }

    public function key(): ProjectKey
    {
        return new ProjectKey($this->key);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws ProjectNotLinked when the project has no owning team
     */
    public function unlink(): void
    {
        if (null === $this->teamId) {
            throw new ProjectNotLinked('The project is not linked to a team.');
        }

        $this->teamId = null;
    }

    /**
     * @throws ProjectAlreadyLinked when the project has an owning team, the same one included
     */
    public function linkTo(TeamId $team): void
    {
        if (null !== $this->teamId) {
            throw new ProjectAlreadyLinked('The project is already linked to a team.');
        }

        $this->teamId = $team->toString();
    }
}
