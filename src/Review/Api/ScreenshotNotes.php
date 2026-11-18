<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One picture of a round as RoundNotes or SharedRoundNotes carries it: its text, its verdict, the pins that count and
 * the media object Media signs its image for.
 */
final readonly class ScreenshotNotes
{
    /**
     * @param string|null   $title   null when the picture has none
     * @param string|null   $summary null when the picture has none
     * @param string|null   $verdict RoundCommands::VERDICT_LOOKS_RIGHT, VERDICT_NOT_RIGHT_YET, or null when none is set
     * @param list<PinNote> $pins    in number order; a person's pins and the AI pins a person kept, never a suggested or
     *                               dismissed AI pin (ADR-0020)
     * @param string        $mediaId the picture's media object, for Media's SignedUrls
     */
    public function __construct(
        public string $screenshotId,
        public int $position,
        public ?string $title,
        public ?string $summary,
        public ?string $verdict,
        public array $pins,
        public string $mediaId,
    ) {
    }
}
