<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;

/**
 * Who reviews the code (FR-WF-14): the lead. When the lead wrote it, the other developer with the fewest open reviews,
 * the lowest user id among equals. With no other developer the lead reviews their own code, and the choice says so.
 */
final readonly class ReviewerChoice
{
    /**
     * The ticket's reviewer: the stored one, else the one choose() finds for its assignee. The turn rules, the Now and
     * Next sentences and the transition policy all ask this, so the person whose turn it is is the one who may approve.
     */
    public function reviewerOf(TicketState $ticket, TeamFacts $team): ChosenPerson
    {
        return null === $ticket->reviewerId ? $this->choose($ticket->assigneeId, $team) : new ChosenPerson($ticket->reviewerId);
    }

    public function choose(?UserId $authorId, TeamFacts $team): ChosenPerson
    {
        if (null === $authorId || !$authorId->equals($team->leadId)) {
            return new ChosenPerson($team->leadId);
        }

        $others = array_values(array_filter(
            $team->developers,
            static fn (TeamMemberLoad $developer): bool => !$developer->userId->equals($team->leadId),
        ));
        if ([] === $others) {
            return new ChosenPerson($team->leadId, \sprintf('%s reviews their own code: no other developer in the team', $team->leadDisplayName));
        }

        usort($others, static fn (TeamMemberLoad $a, TeamMemberLoad $b): int => [$a->openReviews, $a->userId->toString()] <=> [$b->openReviews, $b->userId->toString()]);

        return new ChosenPerson($others[0]->userId);
    }
}
