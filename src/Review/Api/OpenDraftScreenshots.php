<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A person's open unshared draft round of a ticket with its screenshots, one per chain of tries.
 */
final readonly class OpenDraftScreenshots
{
    /**
     * @param RoundAccessFacts           $draft  as RoundPermission VIEW and EDIT decide on it
     * @param list<DraftScreenshotChain> $chains by number; none for a draft with no screenshot
     */
    public function __construct(
        public RoundAccessFacts $draft,
        public array $chains,
    ) {
    }
}
