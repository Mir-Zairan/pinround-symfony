<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * "Share your draft round?", asked when the owner of a private draft round moves its ticket to another step
 * (ADR-0017, hand-off; P05.T9). Review implements it, so the move asks without Ticketing depending on Review. Each
 * method runs in the caller's open transaction, whose tenant is the ticket's organization, and touches only the
 * owner's own draft.
 */
interface ShareDraftQuestion
{
    /**
     * The owner's draft round the move asks about: open, unshared, not decided yet and holding at least one screenshot.
     * Reading the number it would get holds the ticket's round numbers until the caller's transaction ends, as sharing
     * does.
     *
     * @return UndecidedDraft|null null when there is no such draft, so the move asks nothing
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function undecidedDraftOf(TicketId $ticketId, UserId $ownerId): ?UndecidedDraft;

    /**
     * Shares the draft as the ticket's next round number and records the decision "shared", in one go.
     *
     * @return int the round's number, from 1
     *
     * @throws TicketChangedMeanwhile when the draft was shared, decided, deleted or saved by another request since
     *                                undecidedDraftOf read it; part of it may be written by then, so the caller rolls
     *                                back its whole transaction
     * @throws \LogicException        when no transaction is open or no tenant is set
     */
    public function share(UndecidedDraft $draft, UserId $ownerId, \DateTimeImmutable $at): int;

    /**
     * Records the decision "kept private"; the draft stays unshared and the question is not asked again.
     *
     * @throws TicketChangedMeanwhile when the draft was shared, decided, deleted or saved by another request since
     *                                undecidedDraftOf read it; nothing is saved
     * @throws \LogicException        when no transaction is open or no tenant is set
     */
    public function keepPrivate(UndecidedDraft $draft, UserId $ownerId, \DateTimeImmutable $at): void;
}
