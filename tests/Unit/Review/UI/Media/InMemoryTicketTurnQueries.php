<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\TicketTurnQueries;

/**
 * Like the real queries: a ticket of the tenant by id, null for one it has not, and a LogicException for the one whose
 * facts are inconsistent. Records every ticket it is asked for.
 */
final class InMemoryTicketTurnQueries implements TicketTurnQueries
{
    /** @var list<string> */
    public array $askedTicketIds = [];

    /**
     * @param list<TicketTurnSnapshot> $snapshots
     */
    public function __construct(private readonly array $snapshots, private readonly TicketId $brokenTicketId)
    {
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        $this->askedTicketIds[] = $ticketId->toString();
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
