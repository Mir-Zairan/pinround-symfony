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

    /**
     * The project, its row locked for update until the transaction ends, so two changes to the same project's team,
     * or such a change and NextTicketNumber, run one after the other. It reloads the project and drops its unsaved
     * changes.
     *
     * @throws \LogicException when no transaction is open, as the lock would end with the statement
     */
    public function lockById(ProjectId $id): ?Project;

    public function byKey(ProjectKey $key): ?Project;

    /**
     * Saves the project at once, so a key another project of the organization has already fails here: the unique
     * index refuses it, also when another transaction saved that project after byKey() found none.
     *
     * @throws ProjectKeyInUse when the key is taken; the transaction is then failed and the EntityManager closed, so
     *                         the caller must roll back and must not use the ORM again in that request
     */
    public function add(Project $project): void;

    /**
     * Saves the changes to a project found through byId(), lockById() or byKey(), or added before, at once. It
     * flushes the whole unit of work, so every other unsaved change of the request's entities is saved with it.
     *
     * @throws \LogicException when the project was not found or added through this repository
     */
    public function save(Project $project): void;
}
