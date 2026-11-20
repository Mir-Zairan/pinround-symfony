<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * "Share your draft round?", asked when the owner of a private draft round moves its ticket to another step
 * (ADR-0017, hand-off; P05.T9), and the close of the shared round such a move ends (ADR-0048). Review implements it,
 * so the move asks and closes without Ticketing depending on Review. Each method runs in the caller's open
 * transaction, whose tenant is the ticket's organization. An undecided draft is the owner's open, unshared draft with
 * no decision yet and at least one screenshot.
 */
interface ShareDraftQuestion
{
    /**
     * The mover's undecided draft to ask about, with the number it would get and, when a shared round stays open
     * after this move's close, that round, which blocks sharing it. Reading them holds the ticket's round numbers and
     * locks its open shared rounds until the caller's transaction ends, as sharing does; nothing is held when there is
     * no such draft.
     *
     * @return UndecidedDraft|null null when there is no undecided draft, so the move asks nothing
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function undecidedDraftOf(TicketId $ticketId, MoveFacts $move): ?UndecidedDraft;

    /**
     * The id of the undecided draft a move that has its answer already shares or keeps private; reads no number and
     * holds nothing.
     *
     * @return string|null null when there is no undecided draft, so the move decides nothing
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function undecidedDraftIdOf(TicketId $ticketId, UserId $ownerId): ?string;

    /**
     * Closes the ticket's open shared rounds this move closes (ADR-0048): none for a lead-only move; else each the
     * mover's turn closes, and a findings round the assignee's move closes, except a round the mover shared outside a
     * move. Call it after the move is saved and before a share in the same move, so the share is numbered after the
     * close. Holds the ticket's round numbers and locks its open shared rounds until the caller's transaction ends,
     * unless the move cannot close any round.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, or a locked round cannot be closed; the
     *                         caller's transaction must roll back
     */
    public function closeRoundsOnMove(TicketId $ticketId, MoveFacts $move, \DateTimeImmutable $at): void;

    /**
     * Shares the draft as the ticket's next round number and records the decision "shared", in one save.
     *
     * @return int the round's number, from 1
     *
     * @throws MoveRefused            when sharing waits: another shared round of the ticket is still open (the note
     *                                names its number) or the draft has no screenshot; nothing of the share is saved,
     *                                but a move saved before is, so the caller rolls back
     * @throws TicketChangedMeanwhile when the draft was shared, decided, deleted or saved by another request since it
     *                                was read; nothing is saved
     * @throws \LogicException        when no transaction is open or no tenant is set
     */
    public function share(string $roundId, UserId $ownerId, \DateTimeImmutable $at): int;

    /**
     * Records the decision "kept private"; the draft stays unshared and the question is not asked again.
     *
     * @throws TicketChangedMeanwhile when the draft was shared, decided, deleted or saved by another request since it
     *                                was read; nothing is saved
     * @throws \LogicException        when no transaction is open or no tenant is set
     */
    public function keepPrivate(string $roundId, UserId $ownerId, \DateTimeImmutable $at): void;
}
