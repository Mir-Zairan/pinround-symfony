<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One picture of a draft round as RoundNotes carries it: its text, its verdict and its pins.
 */
final readonly class ScreenshotNotes
{
    /**
     * @param string|null   $title   null when the picture has none
     * @param string|null   $summary null when the picture has none
     * @param string|null   $verdict RoundCommands::VERDICT_LOOKS_RIGHT, VERDICT_NOT_RIGHT_YET, or null when none is set
     * @param list<PinNote> $pins    in number order
     */
    public function __construct(
        public string $screenshotId,
        public int $position,
        public ?string $title,
        public ?string $summary,
        public ?string $verdict,
        public array $pins,
    ) {
    }
}
