<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\TeamId;

/**
 * Teams, with their members, of the organization of the current transaction.
 */
interface Teams
{
    /**
     * A new id no team has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): TeamId;

    public function byId(TeamId $id): ?Team;

    /**
     * The team, its row locked for update until the transaction ends, so two changes to the same team's roles run one
     * after the other. Call it before loading the team in the transaction: it reloads the team's own fields and drops
     * their unsaved changes, but members already loaded stay as they were.
     *
     * @throws \LogicException when no transaction is open, as the lock would end with the statement
     */
    public function lockById(TeamId $id): ?Team;

    /**
     * Saves the team and its members at once, so a name another team of the organization has already, in any letter
     * case, fails here: the unique index refuses it and the save throws. A second lead fails only at COMMIT.
     */
    public function add(Team $team): void;

    /**
     * Saves the changes to a team already added, its members included, at once. A second lead fails only at COMMIT.
     */
    public function save(Team $team): void;
}
