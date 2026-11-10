<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * Projects of the organization of the current transaction.
 */
interface Projects
{
    /**
     * A new id no project has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): ProjectId;

    public function byId(ProjectId $id): ?Project;

    public function byKey(ProjectKey $key): ?Project;

    /**
     * Saves the project at once, so a key another project of the organization has already fails here: the unique
     * index refuses it, also when another transaction saved that project after byKey() found none.
     *
     * @throws ProjectKeyInUse when the key is taken; the transaction is then failed and the EntityManager closed, so
     *                         the caller must roll back and must not use the ORM again in that request
     */
    public function add(Project $project): void;
}
