<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One line of a ticket's checklist round: a pin of its Round 0 to check (P05.T6). It starts open; marking it fixed
 * asks for a check, which confirms it OK or sends it back as not fixed. OK is the end. A refused move changes nothing.
 */
final class CheckItem
{
    private function __construct(
        private readonly CheckItemId $id,
        private readonly RoundId $roundId,
        private readonly TicketId $ticketId,
        private readonly PinId $pinId,
        private CheckItemState $state,
        private readonly CheckItemState $savedState,
        private ?UserId $changedBy,
        private ?\DateTimeImmutable $changedAt,
    ) {
    }

    /**
     * A new open item, as Checklist makes one per pin of Round 0.
     */
    public static function open(CheckItemId $id, RoundId $roundId, TicketId $ticketId, PinId $pinId): self
    {
        return new self($id, $roundId, $ticketId, $pinId, CheckItemState::Open, CheckItemState::Open, null, null);
    }

    /**
     * A stored item as Checklists reads it back; its state is the one Checklists::saveItem writes over.
     *
     * @throws \InvalidArgumentException when only one of who changed it and when is given, or an item never changed
     *                                   is in any state but open
     */
    public static function restored(CheckItemId $id, RoundId $roundId, TicketId $ticketId, PinId $pinId, CheckItemState $state, ?UserId $changedBy, ?\DateTimeImmutable $changedAt): self
    {
        if ((null === $changedBy) !== (null === $changedAt)) {
            throw new \InvalidArgumentException('A check item has who changed it exactly when it has when.');
        }
        if (null === $changedBy && CheckItemState::Open !== $state) {
            throw new \InvalidArgumentException('A check item that nobody changed is open.');
        }

        return new self($id, $roundId, $ticketId, $pinId, $state, $state, $changedBy, $changedAt);
    }

    /**
     * @throws \DomainException when the item is marked fixed already or confirmed OK; the item stays as it was
     */
    public function markFixed(UserId $actorId, \DateTimeImmutable $markedAt): void
    {
        if (CheckItemState::Fixed === $this->state) {
            throw new \DomainException('This check is marked fixed already.');
        }
        if (CheckItemState::Ok === $this->state) {
            throw new \DomainException('This check is confirmed OK, so it no longer changes.');
        }

        $this->moveTo(CheckItemState::Fixed, $actorId, $markedAt);
    }

    /**
     * @throws \DomainException when the item is not marked fixed; the item stays as it was
     */
    public function markOk(UserId $actorId, \DateTimeImmutable $checkedAt): void
    {
        if (CheckItemState::Fixed !== $this->state) {
            throw new \DomainException('Only a check marked fixed can be confirmed OK.');
        }

        $this->moveTo(CheckItemState::Ok, $actorId, $checkedAt);
    }

    /**
     * @throws \DomainException when the item is not marked fixed; the item stays as it was
     */
    public function markNotFixed(UserId $actorId, \DateTimeImmutable $checkedAt): void
    {
        if (CheckItemState::Fixed !== $this->state) {
            throw new \DomainException('Only a check marked fixed can be sent back as not fixed.');
        }

        $this->moveTo(CheckItemState::NotFixed, $actorId, $checkedAt);
    }

    public function id(): CheckItemId
    {
        return $this->id;
    }

    public function roundId(): RoundId
    {
        return $this->roundId;
    }

    public function ticketId(): TicketId
    {
        return $this->ticketId;
    }

    public function pinId(): PinId
    {
        return $this->pinId;
    }

    public function state(): CheckItemState
    {
        return $this->state;
    }

    /**
     * The state the stored item had when this one was made or read; Checklists::saveItem writes only over that state.
     */
    public function savedState(): CheckItemState
    {
        return $this->savedState;
    }

    /**
     * Who made the last move; null while the item is as it was made.
     */
    public function changedBy(): ?UserId
    {
        return $this->changedBy;
    }

    public function changedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }

    private function moveTo(CheckItemState $state, UserId $actorId, \DateTimeImmutable $changedAt): void
    {
        $this->state = $state;
        $this->changedBy = $actorId;
        $this->changedAt = $changedAt;
    }
}
