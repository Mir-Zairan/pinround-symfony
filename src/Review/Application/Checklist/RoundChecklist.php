<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

use App\Review\Api\ChecklistView;

/**
 * The check items of one round as RoundChecklistQueries reads them: the ticket's checklist round, whose items are the
 * report's pins (FR-RND-04), or a findings round, a shared draft whose items are its own pins (FR-RND-17).
 */
final readonly class RoundChecklist
{
    /**
     * @param bool                            $isFindings       true for a findings round's items, false for the
     *                                                          checklist round's
     * @param array<string, CheckItemPicture> $picturesByItemId the picture of each item's pin, by item id: the report's
     *                                                          for the checklist round, the round's own for findings
     */
    public function __construct(
        public ChecklistView $checklist,
        public bool $isFindings,
        public array $picturesByItemId,
    ) {
    }
}
