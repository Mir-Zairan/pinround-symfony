<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The mover's own draft round that a move asks about: private, open, not decided yet and with at least one screenshot.
 */
final readonly class UndecidedDraft
{
    /**
     * @param int $screenshotCount from 1
     * @param int $roundNumber     the number the draft gets when it is shared now, from 1
     */
    public function __construct(
        public string $roundId,
        public int $screenshotCount,
        public int $roundNumber,
    ) {
    }
}
