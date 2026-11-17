<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What one person wrote in their open unshared draft round of a ticket, for their own tools to read (P09, ADR-0017:
 * "read my pinround notes and fix what is marked").
 */
final readonly class RoundNotes
{
    /**
     * @param string                $roundId     the draft's id
     * @param list<ScreenshotNotes> $screenshots by position; none for a draft with no picture yet
     */
    public function __construct(
        public string $roundId,
        public array $screenshots,
    ) {
    }
}
