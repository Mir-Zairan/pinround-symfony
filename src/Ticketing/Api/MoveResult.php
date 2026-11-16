<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What a move that was made did besides moving the ticket: the pings it cleared and what it did with the mover's
 * undecided draft round (ADR-0017, hand-off).
 */
final readonly class MoveResult
{
    /**
     * @param int              $clearedPingCount  how many of the actor's open pings on the ticket the move cleared, 0 or more
     * @param DraftChoice|null $draftOutcome      Share when the move shared the actor's draft round, Keep when it recorded
     *                                            it as kept private, null when the actor had no undecided draft to decide
     * @param int|null         $sharedRoundNumber the number the shared draft round got, from 1; set exactly when $draftOutcome is Share
     *
     * @throws \InvalidArgumentException for a negative count, or a round number that does not go with the outcome
     */
    public function __construct(
        public int $clearedPingCount,
        public ?DraftChoice $draftOutcome = null,
        public ?int $sharedRoundNumber = null,
    ) {
        if ($clearedPingCount < 0) {
            throw new \InvalidArgumentException(\sprintf('A cleared ping count is 0 or more, %d given.', $clearedPingCount));
        }
        if ((DraftChoice::Share === $draftOutcome) !== (null !== $sharedRoundNumber) || (null !== $sharedRoundNumber && $sharedRoundNumber < 1)) {
            throw new \InvalidArgumentException('A shared draft round has a number from 1, and no other outcome has one.');
        }
    }
}
