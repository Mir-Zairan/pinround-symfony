<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Adds an uploaded picture to its owner's draft round at most once, for a caller that may send the same upload again,
 * such as an agent retrying a call. It runs in the caller's open transaction, whose tenant is the draft's organization,
 * and refuses as RoundCommands' draft commands do (ADR-0017).
 */
interface DraftScreenshotCommands
{
    /** The kind of Media's UploadTarget of an upload begun for a draft round. */
    public const string UPLOAD_TARGET_KIND = 'round';

    /**
     * Adds the picture after the draft's last one with its title, summary and pins, in one read and one save over the
     * version the draft was read at. When the draft holds that media id already, in any case, it answers that
     * screenshot and saves nothing, so the title, summary and pins sent this time are not used.
     *
     * @param ScreenshotWithPins $screenshot its media id the one Uploads::complete returned for a picture $ownerId
     *                                       uploaded; at most RoundCommands::MAX_PINS_PER_SCREENSHOT pins
     *
     * @throws RoundRefused    more than MAX_PINS_PER_SCREENSHOT pins (isInvalidContent, before anything is read);
     *                         NOT_FOUND (isNotFound); a MEDIA_ refusal as RoundCommands::createReport's; a full draft,
     *                         a text over its cap or a pin outside the picture (isInvalidContent); a shared or closed
     *                         draft, also when it holds the picture (isReadOnly); DRAFT_CHANGED; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function addDraftScreenshotOnce(string $roundId, UserId $ownerId, ScreenshotWithPins $screenshot, \DateTimeImmutable $addedAt): AddedDraftScreenshot;
}
