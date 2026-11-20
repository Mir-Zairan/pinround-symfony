<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What ReportDrafts::addScreenshot did, as it found the draft under its row lock.
 */
final readonly class AddedReportDraftScreenshot
{
    /**
     * @param bool $wasAddedBefore  true when the draft held the picture already and nothing was saved
     * @param int  $screenshotCount the pictures the draft holds now
     */
    public function __construct(
        public bool $wasAddedBefore,
        public int $screenshotCount,
    ) {
    }
}
