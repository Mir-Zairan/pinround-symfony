<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;

/**
 * A step a ticket can be in. Every step sits in one of the five groups; its position orders it inside the group.
 * A core step has a core code and no team, a company step has neither, a team step has a team.
 */
final class Step implements TenantOwned
{
    private readonly string $id;
    private readonly string $organizationId;
    private readonly ?string $teamId;
    private readonly StepOrigin $origin;
    private readonly ?CoreStep $coreCode;
    private readonly string $name;
    private readonly Group $group;
    private readonly int $position;

    /**
     * @throws BlankStepName          when nothing is left of the name after trimming
     * @throws InconsistentStepOrigin when origin, core code, team and group do not fit together
     * @throws DoneGroupStepRefused   when a step other than the core Done step is put in the Done group
     */
    public function __construct(
        StepId $id,
        OrganizationId $organizationId,
        ?TeamId $teamId,
        StepOrigin $origin,
        ?CoreStep $coreCode,
        string $name,
        Group $group,
        int $position,
    ) {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new BlankStepName('A step needs a name.');
        }

        if (StepOrigin::Core === $origin) {
            if (null === $coreCode || null !== $teamId) {
                throw new InconsistentStepOrigin('A core step has a core code and no team.');
            }
            if ($coreCode->group() !== $group) {
                throw new InconsistentStepOrigin('A core step stays in its own group.');
            }
        } else {
            if (null !== $coreCode) {
                throw new InconsistentStepOrigin('Only a core step has a core code.');
            }
            if (StepOrigin::Team === $origin && null === $teamId) {
                throw new InconsistentStepOrigin('A team step needs a team.');
            }
            if (StepOrigin::Company === $origin && null !== $teamId) {
                throw new InconsistentStepOrigin('A company step belongs to no team.');
            }
            if (Group::Done === $group) {
                throw new DoneGroupStepRefused('Only the core Done step is in the Done group.');
            }
        }

        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->teamId = $teamId?->toString();
        $this->origin = $origin;
        $this->coreCode = $coreCode;
        $this->name = $trimmedName;
        $this->group = $group;
        $this->position = $position;
    }

    public function id(): StepId
    {
        return new StepId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function teamId(): ?TeamId
    {
        return null === $this->teamId ? null : new TeamId($this->teamId);
    }

    public function origin(): StepOrigin
    {
        return $this->origin;
    }

    public function coreCode(): ?CoreStep
    {
        return $this->coreCode;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function group(): Group
    {
        return $this->group;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Core steps are never removed; whether a team step is empty of open tickets is checked elsewhere.
     */
    public function isRemovable(): bool
    {
        return StepOrigin::Core !== $this->origin;
    }
}
