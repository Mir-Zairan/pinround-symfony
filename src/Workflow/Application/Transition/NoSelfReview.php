<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Transition;

/**
 * The rule no_self_review: while it is on, the assignee may not approve their own code (Code review → Testing). The
 * note names the ticket's reviewer as ReviewerChoice::reviewerOf gives it, the person whose turn it is.
 *
 * When that reviewer is the assignee, the reviewer choice found nobody else (workflow-engine.md, "Fallbacks": the lead
 * reviews their own code), so the rule lets it through; the move records that override in the history.
 */
final readonly class NoSelfReview implements Guard
{
    public function __construct(private ReviewerChoice $reviewerChoice)
    {
    }

    public function check(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): ?RuleNote
    {
        if (!$rules->noSelfReview || !$transition->startsFrom(CoreStep::Review) || CoreStep::Test !== $transition->to) {
            return null;
        }

        $assigneeId = $ticket->assigneeId;
        if (null === $assigneeId || !$assigneeId->equals($actor->userId)) {
            return null;
        }

        $reviewerId = $this->reviewerChoice->reviewerOf($ticket, $team)->userId;
        if ($reviewerId->equals($assigneeId)) {
            return null;
        }

        return new RuleNote('Team rule: nobody approves their own code. {reviewer} reviews it.', ['reviewer' => $reviewerId]);
    }
}
