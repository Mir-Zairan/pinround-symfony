<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;

/**
 * Who tests (FR-WF-14): the team tester with the fewest tickets in Testing, the lowest user id among equals. A team
 * without a tester sends the Testing turn to the lead.
 */
final readonly class TesterChoice
{
    /**
     * The ticket's tester: the stored one, else the one choose() finds. The turn rules, the Now and Next sentences and
     * the transition policy all ask this, so the person whose turn it is is one who may pass it or send it back.
     */
    public function testerOf(TicketState $ticket, TeamFacts $team): ChosenPerson
    {
        return null === $ticket->testerId ? $this->choose($team) : new ChosenPerson($ticket->testerId);
    }

    public function choose(TeamFacts $team): ChosenPerson
    {
        $testers = $team->testers;
        if ([] === $testers) {
            return new ChosenPerson($team->leadId);
        }

        usort($testers, static fn ($a, $b): int => [$a->ticketsInTesting, $a->userId->toString()] <=> [$b->ticketsInTesting, $b->userId->toString()]);

        return new ChosenPerson($testers[0]->userId);
    }
}
