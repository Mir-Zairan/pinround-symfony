<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * What a ticket move needs to know about its mover's open unshared draft, without its screenshots and pins.
 */
final readonly class DraftFacts
{
    /**
     * @param int $screenshotCount from 0
     */
    public function __construct(
        public RoundId $id,
        public ?DraftDecision $decision,
        public int $screenshotCount,
    ) {
    }
}
