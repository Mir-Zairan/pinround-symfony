<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Whose turn tickets are, for other modules' lists. It reads ins_ticket_turn of the organization of TenantContext
 * under row-level security, so it needs an open transaction whose tenant is that organization.
 */
interface TurnUserQueries
{
    /**
     * One query; an empty $ticketIds runs none. It does not filter on visibility: pass only ids the viewer may see,
     * such as the rows of a list read with the visibility predicate.
     *
     * @param list<TicketId> $ticketIds
     *
     * @return array<string, UserId> the turn user by ticket id string; a ticket whose turn is a label's (such as
     *                               Customer), that has no turn row, or that is another organization's is left out
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function turnUsersOf(array $ticketIds): array;

    /**
     * The tickets whose turn is that person's now, at most $max of them, highest base score first; one query by
     * ins_ticket_turn_turn_user_id_base_score_idx. It does not filter on visibility: the caller's list applies its own.
     *
     * @param int $max at least 1
     *
     * @return list<string> ticket ids as canonical uuids
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when $max is below 1, before any query
     */
    public function ticketIdsWaitingOn(UserId $userId, int $max): array;
}
