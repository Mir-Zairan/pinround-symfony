<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TurnPeopleChoice;

/**
 * The person ReviewerChoice and TesterChoice pick from the team's loads at the move, except their fallbacks, which
 * stay unstored (workflow-engine.md, "Transition catalogue", Fallbacks).
 */
final readonly class LoadBasedTurnPeopleChoice implements TurnPeopleChoice
{
    public function __construct(
        private ReviewerChoice $reviewerChoice,
        private TesterChoice $testerChoice,
    ) {
    }

    public function reviewerToStore(?UserId $assigneeId, TeamFacts $team): ?UserId
    {
        $reviewer = $this->reviewerChoice->choose($assigneeId, $team);

        return null === $reviewer->overrideNote ? $reviewer->userId : null;
    }

    public function testerToStore(TeamFacts $team): ?UserId
    {
        return [] === $team->testers ? null : $this->testerChoice->choose($team)->userId;
    }
}
