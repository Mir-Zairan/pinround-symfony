<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What was marked in the newest shared round of a ticket that holds pictures, the report (Round 0) or a shared draft
 * round, for a developer's own tools to read (P09, ADR-0017).
 */
final readonly class SharedRoundNotes
{
    public const string REPORT = 'report';
    public const string DRAFT = 'draft';

    /**
     * @param int                   $number      0 for the report, 1 and up for a shared draft round
     * @param string                $kind        REPORT or DRAFT
     * @param list<ScreenshotNotes> $screenshots by position; none for a round with no picture
     */
    public function __construct(
        public string $roundId,
        public int $number,
        public string $kind,
        public array $screenshots,
    ) {
    }
}
