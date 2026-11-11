<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * Keeps the ticket's row in ins_ticket_turn, the turn read model of ADR-0015, in step with the ticket. Every
 * transaction that changes the turn or a column the row copies calls it: team_id, project_id, grp, visibility,
 * reporter_id, assignee_id, pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id, check_user_id, and the
 * priority, release and risk check behind base_score. Home and Whose turn filter on the copied visibility columns, so
 * a stale row shows a restricted ticket to people who may no longer see it.
 */
interface TurnProjector
{
    /**
     * Reads the ticket's facts and writes its row with one upsert, or deletes the row when the ticket is done, is
     * nobody's turn or no longer exists. Reads no other ticket. waiting_since is kept while the turn's person or label
     * stays the same and starts now when either changes.
     *
     * @return string|null the turn's override note when a fallback chose the person, for the caller to write to the
     *                     ticket's activity, for example "Lena reviews their own code: no other developer in the
     *                     team"; returned on every refresh while that fallback holds, so the caller writes it only
     *                     when its change moved the turn. Null when no fallback was needed or the ticket has no row.
     *
     * @throws \LogicException when no transaction is open: the row must share the fate of the change
     */
    public function refresh(TicketId $ticketId): ?string;
}
