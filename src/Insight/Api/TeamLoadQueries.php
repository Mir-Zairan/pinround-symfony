<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * How many reviews and tests wait for people, counted from ins_ticket_turn of the organization of TenantContext under
 * row-level security, so it needs an open transaction whose tenant is that organization.
 */
interface TeamLoadQueries
{
    /**
     * One query; an empty $userIds runs none. The ticket's own row is left out, so a ticket in review does not count
     * against its own reviewer.
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, array<string, int>> tickets waiting by user id string and kind ("review" or "test"); a
     *                                           person or kind with none is left out
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function waitingTurnsOf(array $userIds, TicketId $excludedTicketId): array;
}
