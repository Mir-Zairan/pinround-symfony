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
     * Saves the team and its members at once, so a name another team of the organization has already, in any letter
     * case, fails here: the unique index refuses it and the save throws.
     */
    public function add(Team $team): void;

    /**
     * Saves the changes to a team already added, its members included, at once.
     */
    public function save(Team $team): void;
}
