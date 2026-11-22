<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * The verdict on the shown screenshot of a shared draft in its side panel, as the prototype's "Your verdict on <area>":
 * Pass, Needs fix or Question with an optional note for whoever may give verdicts, and "Verdict on …" to read for
 * everyone else.
 */
final readonly class ScreenshotVerdictSection
{
    /**
     * @param string      $screenshotId   the shown screenshot's id, lowercase, which the form posts to
     * @param string      $screenshotName its title, or "Screenshot N"
     * @param string|null $verdict        VerdictCommands::AREA_PASS, AREA_NEEDS_FIX or AREA_QUESTION; null with none yet
     * @param string|null $verdictLabel   for example "Needs fix"; null with no verdict
     * @param string|null $note           the verdict's note; null with none
     * @param string|null $decidedByName  who saved it; null with no verdict or a name Identity does not know
     * @param bool        $mayGiveVerdict whether the form shows, as RoundPages::mayGiveVerdictsOn decides
     * @param string|null $leadName       the team lead, who sees the verdict next ("Then: …"); null on a ticket with none
     */
    public function __construct(
        public string $screenshotId,
        public string $screenshotName,
        public ?string $verdict,
        public ?string $verdictLabel,
        public ?string $note,
        public ?string $decidedByName,
        public bool $mayGiveVerdict,
        public ?string $leadName,
    ) {
    }
}
