<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The mover has an undecided draft round on the ticket and did not say whether to share it or keep it private, so the
 * ticket was not moved; the caller asks "Share your draft round?" and moves again with a DraftChoice.
 */
final class DraftDecisionNeeded extends \DomainException
{
    public function __construct(public readonly UndecidedDraft $draft)
    {
        parent::__construct('The ticket was not moved: the mover has not decided about their draft round.');
    }
}
