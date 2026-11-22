<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * One general note in the round page's "General notes on this round".
 */
final readonly class GeneralNoteRow
{
    /**
     * @param string|null $authorName   the author's first name; null when Identity does not know them
     * @param string|null $verdictLabel "Pass", "Needs fix" or "Question"; null for a note with no verdict
     * @param string      $when         relative, for example "5 min ago"
     */
    public function __construct(
        public ?string $authorName,
        public ?string $verdictLabel,
        public string $note,
        public string $when,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
