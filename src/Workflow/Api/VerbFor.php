<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The short task for the person whose turn it is, shown on Home and in lists ("Test it", "Review Omar's code"). Pure.
 */
interface VerbFor
{
    /**
     * @return string|null null when nobody holds the turn (the ticket is done)
     */
    public function person(TicketState $ticket, Turn $turn, NowNextContext $context): ?string;
}
