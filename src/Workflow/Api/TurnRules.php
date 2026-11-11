<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Whose turn a ticket is (workflow-engine.md, "Whose turn"). Pure: nothing is read from the database, and nothing is
 * written; the caller stores the result (Insight's read model) and writes any override note to the ticket's activity.
 */
interface TurnRules
{
    /**
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function turnFor(TicketState $ticket, TeamFacts $team): Turn;
}
