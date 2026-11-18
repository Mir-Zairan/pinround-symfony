<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\Gateway\AskedTicket;
use App\Assist\Application\Gateway\AskedTicketQueries;
use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\TicketId;

/**
 * Tickets by id, each with the users who may see it; anyone else gets null, as for an unknown id.
 */
final class ListedAskedTickets implements AskedTicketQueries
{
    /** @var array<string, array{ticket: AskedTicket, viewerIds: list<string>}> */
    private array $tickets = [];

    /** @var list<string> the id of each ticket read */
    public array $reads = [];

    /**
     * @param list<string> $viewerIds
     */
    public function add(TicketId $ticketId, AskedTicket $ticket, array $viewerIds): void
    {
        $this->tickets[$ticketId->toString()] = ['ticket' => $ticket, 'viewerIds' => $viewerIds];
    }

    public function visibleTicketOf(TicketId $ticketId, ActorContext $viewer): ?AskedTicket
    {
        $this->reads[] = $ticketId->toString();
        $listed = $this->tickets[$ticketId->toString()] ?? null;
        if (null === $listed || !\in_array($viewer->userId()->toString(), $listed['viewerIds'], true)) {
            return null;
        }

        return $listed['ticket'];
    }
}
