<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\ReleasePlacement;
use App\Workflow\Api\TurnRules;

/**
 * Decides a ticket's turn row from its facts with Workflow's turn rules and score; pure, so the writer only stores it.
 */
final readonly class TurnRowResolver
{
    public function __construct(private TurnRules $turnRules, private NeedsYouScore $needsYouScore)
    {
    }

    /**
     * @return TicketTurnRow|null null when the ticket gets no row: it is done (also with a question still open), or
     *                            the rules find nobody's turn
     *
     * @throws \InvalidArgumentException when the turn rules or the score refuse the ticket's core code or priority
     */
    public function rowFor(TicketTurnFacts $facts): ?TicketTurnRow
    {
        $ticket = $facts->ticket;
        if ('done' === $ticket->groupCode) {
            return null;
        }

        $turn = $this->turnRules->turnFor($ticket, $facts->team);
        // Only a ticket in the core step Done outside the group done reaches this; nobody acts, so nothing waits.
        if (null === $turn->kind || (null === $turn->userId && null === $turn->label)) {
            return null;
        }

        return new TicketTurnRow(
            $facts,
            $turn->userId,
            null === $turn->userId ? $turn->label : null,
            $turn->kind,
            $this->needsYouScore->baseScore($turn->kind, $ticket->priority, $this->placementOf($facts), $facts->isRiskCheck),
            $ticket->isCheckNeeded ? $ticket->checkerId : null,
            $turn->overrideNote,
        );
    }

    private function placementOf(TicketTurnFacts $facts): ReleasePlacement
    {
        return match (true) {
            null === $facts->ticket->release => ReleasePlacement::NoRelease,
            $facts->ticket->release === $facts->currentRelease => ReleasePlacement::InCurrentRelease,
            default => ReleasePlacement::InOtherRelease,
        };
    }
}
