<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Domain\Round;
use App\Review\Domain\Rounds;

/**
 * Closes a shared round read under {@see Rounds::openSharedRoundsOf}'s locks (ADR-0048), so the share that closes a
 * findings round and the move that closes the open round write the close one way.
 */
interface LockedRoundClosing
{
    /**
     * Closes the round and saves it in the caller's transaction. Who may close it is the caller's rule.
     *
     * @throws \LogicException when the round cannot be closed or saved, which the row lock it was read under rules out;
     *                         the caller's transaction must roll back
     */
    public function closeLockedRound(Round $round, \DateTimeImmutable $closedAt): void;
}
