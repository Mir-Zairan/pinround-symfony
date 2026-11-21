<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Review\UI\Checklist\ChecklistCard;

/**
 * One round as the ticket page's Rounds section shows it: a heading line, an optional status line, and, for the report
 * and for drafts, its screenshots with their pins. The checklist round shows its rows. A review round and a shared draft
 * are one-line rows that open their own page; a findings round's check items stay under its row.
 */
final readonly class RoundCard
{
    /**
     * @param string                    $domId               a lowercase id, unique on the page
     * @param string                    $headline            for example "Round 0 · Report · by Mia · 2 pins"
     * @param string|null               $status              for example "1 of 2 look right"; null when there is none to say
     * @param bool                      $isPrivateDraft      the viewer's own unshared draft, which carries the PRIVATE tag
     * @param list<RoundCardScreenshot> $screenshots         empty for a round that is only a row
     * @param bool                      $hasOpenRound        whether the card carries the "Open round" button
     * @param string|null               $moreScreenshotsNote for example "2 more screenshots are not shown."; null when all are shown
     * @param string|null               $description         the muted line under a draft's header; null for the other rounds
     * @param string|null               $emptyNote           what a draft with no screenshot says; null for the other rounds
     * @param int|null                  $number              the round's number, which "Open round" and "Full screen" link to;
     *                                                       set with $hasOpenRound and $hasFullScreen
     * @param string                    $roundId             the round's id, which the private draft's Share and Delete forms post to
     * @param ChecklistCard|null        $checklist           the checklist round's rows, which the card shows in place of its header; null
     *                                                       for every other round
     * @param ChecklistCard|null        $findingsChecklist   a findings round's check items, which show under its row or pictures;
     *                                                       null for every other round
     * @param bool                      $isRow               a later round shown as one line, for example
     *                                                       "Round 2 · review by Lena · open"; false for the report, the
     *                                                       checklist and the viewer's own unshared draft
     * @param bool                      $hasFullScreen       whether the card carries the "Full screen" link to the
     *                                                       full-screen review: only the report's, when it has a
     *                                                       screenshot
     */
    public function __construct(
        public string $roundId,
        public string $domId,
        public string $headline,
        public ?string $status,
        public bool $isPrivateDraft,
        public array $screenshots,
        public bool $hasOpenRound,
        public ?string $moreScreenshotsNote = null,
        public ?string $description = null,
        public ?string $emptyNote = null,
        public ?int $number = null,
        public ?ChecklistCard $checklist = null,
        public ?ChecklistCard $findingsChecklist = null,
        public bool $isRow = false,
        public bool $hasFullScreen = false,
    ) {
    }
}
