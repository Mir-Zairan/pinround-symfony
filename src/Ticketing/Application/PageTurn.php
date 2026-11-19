<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;

/**
 * What the ticket page read once about a ticket of a team and its turn, for the Now and Next lines
 * ({@see SavedTicketPageTurns::linesOf}) and the "Your next step" box (the UI's NextStepChoices).
 */
final readonly class PageTurn
{
    /**
     * @param NowNextContext $context names people by first name and has no viewer, so its sentences never say "you"
     */
    public function __construct(
        public TeamId $teamId,
        public TicketState $ticket,
        public TeamFacts $team,
        public Turn $turn,
        public NowNextContext $context,
        public RuleSetView $rules,
    ) {
    }
}
