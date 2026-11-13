<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

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
     * stays the same and starts now when either changes. Also moves the ticket's count on the project rail
     * (ins_project_counts) to its project, team, visibility and done or open, done tickets included; a ticket with no
     * team counts nowhere.
     *
     * @return string|null the turn's override note when a fallback chose the person, for the caller to write to the
     *                     ticket's activity, for example "Lena reviews their own code: no other developer in the
     *                     team"; returned on every refresh while that fallback holds, so the caller writes it only
     *                     when its change moved the turn. Null when no fallback was needed or the ticket has no row.
     *
     * @throws \LogicException when no transaction is open: the row must share the fate of the change; or when the
     *                         count row the ticket was in is missing, so the change must roll back
     */
    public function refresh(TicketId $ticketId): ?string;

    /**
     * Does what {@see refresh} does, from the ticket's facts its caller has already read with TicketTurnQueries::factsOf
     * (or the ticket page's TurnSnapshotRow), so the ticket is not read again: it writes the same row and count refresh
     * would for that snapshot. Reads no ticket. The caller vouches that the snapshot is the ticket as it stands in its
     * open transaction, after its change; a stale snapshot saves a stale turn row and count until the next refresh.
     * A ticket the snapshot gives no team has its row deleted and counts nowhere.
     *
     * @return string|null the turn's override note, as refresh returns it
     *
     * @throws \LogicException as refresh does: when no transaction is open, or when the count row the ticket was in is
     *                         missing, so the change must roll back
     */
    public function refreshFrom(TicketTurnSnapshot $facts): ?string;
}
