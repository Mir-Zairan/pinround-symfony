<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * What the round bar shows of the ticket's open shared round.
 */
final readonly class RoundBarView
{
    /**
     * @param int         $roundNumber  from 1
     * @param string|null $readyForLine "Ready for Mia" or "Ready for you"; null when nobody is known
     */
    public function __construct(
        public int $roundNumber,
        public ?string $readyForLine,
    ) {
    }
}
