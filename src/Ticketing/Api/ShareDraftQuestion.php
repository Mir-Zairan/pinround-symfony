<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * "Share your draft round?", asked when the owner of a private draft round moves its ticket to another step
 * (ADR-0017, hand-off; P05.T9). Review implements it, so the move asks without Ticketing depending on Review. Each
 * method runs in the caller's open transaction, whose tenant is the ticket's organization, and touches only the
 * owner's own draft. An undecided draft is the owner's open, unshared draft with no decision yet and at least one
 * screenshot.
 */
interface ShareDraftQuestion
{
    /**
     * The undecided draft to ask about, with the number it would get. Reading that number holds the ticket's round
     * numbers until the caller's transaction ends, as sharing does; nothing is held when there is no such draft.
     *
     * @return UndecidedDraft|null null when there is no undecided draft, so the move asks nothing
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function undecidedDraftOf(TicketId $ticketId, UserId $ownerId): ?UndecidedDraft;

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
     * Shares the draft as the ticket's next round number and records the decision "shared", in one save.
     *
     * @return int the round's number, from 1
     *
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
