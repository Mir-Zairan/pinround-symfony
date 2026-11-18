<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The screenshot of a draft round that holds a picture, as DraftScreenshotCommands::addDraftScreenshotOnce answers it.
 */
final readonly class AddedDraftScreenshot
{
    /**
     * @param bool $wasAddedBefore true when the draft held the picture already and nothing was saved
     * @param int  $pinCount       the pins the screenshot has now
     */
    public function __construct(
        public string $screenshotId,
        public bool $wasAddedBefore,
        public int $pinCount,
    ) {
    }
}
