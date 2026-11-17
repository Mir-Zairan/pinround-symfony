<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;

/**
 * Who counts as a ticket's tester: the stored tester, else any tester of the ticket's team while it has none stored.
 */
final class TicketTesterRule
{
    public static function isTesterOf(TicketTurnSnapshot $ticket, UserId $userId): bool
    {
        if (null !== $ticket->testerId) {
            return $ticket->testerId->equals($userId);
        }

        return array_any($ticket->testerIds, static fn ($teamTesterId) => $teamTesterId->equals($userId));
    }
}
