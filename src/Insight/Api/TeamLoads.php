<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * The waiting turns of some people and one ticket's saved turn, read together.
 */
final readonly class TeamLoads
{
    /**
     * @param array<string, array<string, int>> $waitingTurns tickets waiting by user id string and kind, as
     *                                                        {@see TeamLoadQueries::waitingTurnsOf}
     * @param SavedTurn|null                    $savedTurn    null when the ticket has no turn row (it is done, or no team owns it)
     */
    public function __construct(public array $waitingTurns, public ?SavedTurn $savedTurn)
    {
    }
}
