<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * Steps of the organization of the current transaction.
 */
interface Steps
{
    /**
     * A new id no step has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): StepId;

    public function byId(StepId $id): ?Step;

    /**
     * Saves the steps at once, in one flush. A second core step with the same core code fails here with the
     * database's unique violation; the transaction is then failed and the EntityManager closed, so the caller must
     * roll back.
     */
    public function add(Step ...$steps): void;
}
