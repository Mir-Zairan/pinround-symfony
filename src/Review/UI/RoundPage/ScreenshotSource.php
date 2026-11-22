<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\SharedKernel\Domain\UserId;

/**
 * Which shared round's screenshot a picture of the round page is, for "Pin this in my draft"
 * (DraftRoundController::copyScreenshot): a checklist round shows the report's, so the round is not always the page's.
 */
final readonly class ScreenshotSource
{
    /**
     * @param string $screenshotId the latest try the page shows
     * @param UserId $roundOwnerId who owns the round, whom RoundPermission COPY_TO_OWN_DRAFT refuses
     */
    public function __construct(
        public string $roundId,
        public string $screenshotId,
        public UserId $roundOwnerId,
    ) {
    }
}
