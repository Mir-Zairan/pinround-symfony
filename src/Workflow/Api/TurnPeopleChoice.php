<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Who a ticket entering Code review or Testing stores as its reviewer or tester (FR-WF-14), so the turn, the Now and Next
 * sentences and the transition policy keep naming that person while the team's loads change. Pure.
 */
interface TurnPeopleChoice
{
    /**
     * @param UserId|null $assigneeId who wrote the code; the lead reviews unless it is the lead
     *
     * @return UserId|null null when the lead reviews their own code for want of another developer: nothing is stored,
     *                     so the turn keeps writing that note to the ticket's activity
     */
    public function reviewerToStore(?UserId $assigneeId, TeamFacts $team): ?UserId;

    /**
     * @return UserId|null null when the team has no tester: nothing is stored, so the Testing turn goes to the lead
     *                     and to a tester once the team has one
     */
    public function testerToStore(TeamFacts $team): ?UserId;
}
