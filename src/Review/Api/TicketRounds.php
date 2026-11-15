<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The rounds of a ticket a viewer is shown, and how many older ones the cap left out.
 */
final readonly class TicketRounds
{
    /**
     * @param list<RoundView> $rounds
     * @param int             $olderRoundsNotShown numbered rounds past RoundQueries::MAX_NUMBERED_ROUNDS that the viewer
     *                                             could see but were not loaded; never below 0
     */
    public function __construct(public array $rounds, public int $olderRoundsNotShown = 0)
    {
        if ($olderRoundsNotShown < 0) {
            throw new \InvalidArgumentException('A count of rounds left out is not below 0.');
        }
    }
}
