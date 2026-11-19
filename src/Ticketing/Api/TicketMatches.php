<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The best matches of a search, best first, and whether more matched than were returned.
 */
final readonly class TicketMatches
{
    /**
     * @param list<TicketMatch> $tickets
     */
    public function __construct(public array $tickets, public bool $hasMore)
    {
    }
}
