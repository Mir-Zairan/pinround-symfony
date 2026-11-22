<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The move an Undo asks to take back, as Ticketing's history knows it; TransitionPolicy::checkUndo decides on it.
 */
final readonly class MoveToUndo
{
    /**
     * @param string $toStepId     the step the move entered
     * @param bool   $isLastChange whether the move is still the last thing that happened to the ticket: no move, give,
     *                             question or review change since, and the ticket saved by no one since
     */
    public function __construct(
        public UserId $movedBy,
        public string $toStepId,
        public \DateTimeImmutable $movedAt,
        public bool $isLastChange,
    ) {
    }
}
