<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * One round as the ticket page's Rounds section shows it: a heading line, an optional status line, and, for the report
 * and for drafts, its screenshots with their pins. A checklist or review round is a compact row that opens its own page.
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
     * @param int|null                  $number              the round's number, which "Open round" links to; set with $hasOpenRound
     * @param string                    $roundId             the round's id, which the private draft's Share and Delete forms post to
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
    ) {
    }
}
