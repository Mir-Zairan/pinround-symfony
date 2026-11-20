<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

use App\Review\Api\ChecklistCommands;
use App\Review\Domain\Round;

/**
 * ChecklistCommands with the findings step of a share, for Review's own round commands. It sits beside ChecklistCommands
 * rather than in it because it takes the Round the share has just saved, which no other module holds.
 */
interface RoundChecklistCommands extends ChecklistCommands
{
    /**
     * Makes one open item per pin of a shared draft round whose owner is not the ticket's assignee (a findings round,
     * ADR-0044, FR-RND-17), on that round, which the assignee marks fixed and the tester checks. A self-check round,
     * whose owner is the assignee, gets none. It reads the ticket's assignee only for a round with pins, and the round
     * not at all. Call it once, in the transaction that shares the round, so a failure here rolls the share back too.
     * No event is published.
     *
     * @param Round $sharedDraft the round as the share saved it
     *
     * @return int the number of items made; 0 for a self-check round or a round without pins
     *
     * @throws \LogicException when no transaction is open, no tenant is set, the round is not the tenant's shared draft,
     *                         its ticket cannot be read, or the round has items already (a second call)
     */
    public function createFromFindings(Round $sharedDraft, \DateTimeImmutable $at): int;
}
