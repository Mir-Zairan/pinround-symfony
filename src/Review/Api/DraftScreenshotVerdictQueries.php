<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The verdicts on the screenshots of shared drafts (VerdictCommands::saveScreenshotVerdict). Reads under the caller's
 * open transaction with the organization as tenant; the caller must have been granted RoundPermission VIEW on the round.
 */
interface DraftScreenshotVerdictQueries
{
    /**
     * One query, by the verdict's key.
     *
     * @param string $roundId      the round's id, lowercase
     * @param string $screenshotId the screenshot's id, lowercase
     *
     * @return DraftScreenshotVerdictView|null null when nobody gave the screenshot a verdict, or it is not the round's
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function verdictOf(string $roundId, string $screenshotId): ?DraftScreenshotVerdictView;
}
