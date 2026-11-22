<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The screenshot RoundCommands::copyScreenshotToDraft added to its person's draft round.
 */
final readonly class CopiedDraftScreenshot
{
    /**
     * @param string $draftRoundId     the draft's id, lowercase
     * @param string $screenshotId     the new screenshot's id, lowercase
     * @param int    $screenshotNumber from 1, the screenshot's place in the draft, as the draft page numbers its tabs
     * @param bool   $isDraftStarted   true when the copy started the draft, as the person had none on the ticket
     */
    public function __construct(
        public string $draftRoundId,
        public string $screenshotId,
        public int $screenshotNumber,
        public bool $isDraftStarted,
    ) {
    }
}
