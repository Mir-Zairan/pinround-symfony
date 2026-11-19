<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * What the check reasons need besides the report's words: where it goes, what the page showed and what the AI said.
 */
final readonly class ReportContext
{
    /**
     * @param bool $hasTeam                   false for "Not sure"
     * @param int  $pinCount                  the pins on its screenshot
     * @param bool $wasDuplicateShown         at least one possible duplicate was shown
     * @param bool $aiSaysInfoIsMissing       the intake check answered missing: true
     * @param bool $aiSaysRisky               the intake check answered risky: true
     * @param bool $everyNewTicketNeedsACheck the admin's rule "Every new ticket needs a lead check"
     */
    public function __construct(
        public bool $hasTeam,
        public int $pinCount,
        public bool $wasDuplicateShown = false,
        public bool $aiSaysInfoIsMissing = false,
        public bool $aiSaysRisky = false,
        public bool $everyNewTicketNeedsACheck = false,
    ) {
    }
}
