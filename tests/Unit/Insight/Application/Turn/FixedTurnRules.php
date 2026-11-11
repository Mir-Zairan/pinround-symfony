<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\Turn;

use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnRules;

/**
 * Turn rules that give every ticket the same turn and count how often they were asked.
 */
final class FixedTurnRules implements TurnRules
{
    public int $timesAsked = 0;

    public function __construct(private readonly Turn $turn)
    {
    }

    public function turnFor(TicketState $ticket, TeamFacts $team): Turn
    {
        ++$this->timesAsked;

        return $this->turn;
    }
}
