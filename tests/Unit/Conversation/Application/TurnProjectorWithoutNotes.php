<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\Insight\Api\TurnProjector;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * A turn read model no test reads: every refresh writes nothing and no fallback chose the person.
 */
final readonly class TurnProjectorWithoutNotes implements TurnProjector
{
    public function refresh(TicketId $ticketId): ?string
    {
        return null;
    }

    public function refreshFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): ?string
    {
        return null;
    }
}
