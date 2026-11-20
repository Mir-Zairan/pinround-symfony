<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\LockedTicketTurnQueries;

/**
 * Like the real queries: a ticket of the tenant by id, null for one it has not, and a LogicException for the one whose
 * facts are inconsistent. Records every ticket it is asked for, and apart from those, every ticket it locks.
 */
final class InMemoryTicketTurnQueries implements LockedTicketTurnQueries
{
    /** @var list<string> */
    public array $askedTicketIds = [];

    /** @var list<string> the ticket ids lockedFactsOf was asked for, in order: each stands for the row lock DbalTicketTurnQueries takes */
    public array $lockedTicketIds = [];

    /**
     * @param list<TicketTurnSnapshot> $snapshots
     */
    public function __construct(private readonly array $snapshots, private readonly TicketId $brokenTicketId)
    {
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        $this->askedTicketIds[] = $ticketId->toString();

        return $this->snapshotOf($ticketId);
    }

    public function lockedFactsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        $this->lockedTicketIds[] = $ticketId->toString();

        return $this->snapshotOf($ticketId);
    }

    private function snapshotOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        if ($ticketId->equals($this->brokenTicketId)) {
            throw new \LogicException('A ticket is in a step its organization does not have.');
        }
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->ticketId->equals($ticketId)) {
                return $snapshot;
            }
        }

        return null;
    }
}
