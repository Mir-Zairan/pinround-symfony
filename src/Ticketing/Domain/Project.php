<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;

/**
 * A project of an organization, owned by one of its teams (FR-ORG-06). Its tickets are numbered from 1 up without
 * gaps; the counter is not kept on this object but handed out by NextTicketNumber, straight in the database.
 */
final class Project implements TenantOwned
{
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $teamId;
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

    public function owningTeam(): TeamId
    {
        return new TeamId($this->teamId);
    }

    public function key(): ProjectKey
    {
        return new ProjectKey($this->key);
    }

    public function name(): string
    {
        return $this->name;
    }
}
