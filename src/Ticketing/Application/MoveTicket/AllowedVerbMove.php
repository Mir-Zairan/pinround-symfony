<?php

declare(strict_types=1);

namespace App\Ticketing\Application\MoveTicket;

use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;

/**
 * A verb move the policy allowed, with what was read to decide it, so saving the move reads none of it again.
 */
final readonly class AllowedVerbMove
{
    /**
     * @param TicketTurnSnapshot                $before         the ticket before the move
     * @param array<string, array<string, int>> $waitingTurns   the team's waiting turns, read once for the move
     * @param TeamFacts                         $teamFacts      the team facts the policy decided on
     * @param StepView                          $target         the team's step the verb leads to
     * @param bool                              $isLeadOnlyMove whether only the team's lead may use the verb
     */
    public function __construct(
        public TicketTurnSnapshot $before,
        public array $waitingTurns,
        public TeamFacts $teamFacts,
        public StepView $target,
        public bool $isLeadOnlyMove,
    ) {
    }
}
