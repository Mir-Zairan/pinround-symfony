<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

use App\SharedKernel\Domain\TeamId;

/**
 * Steps of the organization of the current transaction.
 */
interface Steps
{
    /**
     * A new id no step has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): StepId;

    /**
     * Removed steps are found too.
     */
    public function byId(StepId $id): ?Step;

    /**
     * Saves the steps at once, in one flush. A second core step with the same core code fails here with the
     * database's unique violation; the transaction is then failed and the EntityManager closed, so the caller must
     * roll back.
     */
    public function add(Step ...$steps): void;

    /**
     * Saves the changes to a step found through byId at once. It flushes, so any other pending change of the
     * EntityManager is written in the same flush.
     *
     * @throws \LogicException when the step was neither found through byId nor added in this unit of work
     */
    public function save(Step $step): void;

    /**
     * The highest position among the steps in the group that are listed with $teamId: the core and company steps,
     * plus that team's own steps when a team is given. Removed steps are left out. Null when there are none.
     */
    public function highestPositionIn(Group $group, ?TeamId $teamId): ?int;
}
