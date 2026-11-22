<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The verdict on one screenshot of a shared draft, as DraftScreenshotVerdictQueries reads it.
 */
final readonly class DraftScreenshotVerdictView
{
    /**
     * @param string      $verdict VerdictCommands::AREA_PASS, AREA_NEEDS_FIX or AREA_QUESTION
     * @param string|null $note    trimmed; null when it has none
     */
    public function __construct(
        public string $verdict,
        public ?string $note,
        public UserId $decidedBy,
    ) {
    }
}
