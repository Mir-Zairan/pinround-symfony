<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\TicketId;

/**
 * The checklist rounds of tickets with their items, and the items of findings rounds. A checklist round is shared with
 * no number (ADR-0048, note of 2026-11-22), so it never takes one a hand-off round could get. Each method needs an open
 * transaction under the organization as tenant, and reads or writes that organization's checklists only.
 */
interface Checklists
{
    public function nextItemIdentity(): CheckItemId;

    /**
     * Writes the checklist round with its items, all or nothing.
     *
     * @throws RoundSlotTaken  when the ticket has a checklist round already
     * @throws \LogicException when no transaction is open, the round id was added before (a bug or a redelivered
     *                         message, not a slot), or an item's id was added before or its pin is not stored or is in a
     *                         checklist already
     */
    public function add(Checklist $checklist): void;

    /**
     * Writes the items of a findings round, all or nothing; the round is stored already.
     *
     * @throws \LogicException when no transaction is open, or an item's id was added before or its pin is not stored or
     *                         is in a checklist already (a second call for the round)
     */
    public function addFindings(FindingsChecklist $checklist): void;

    /**
     * The pins of the ticket's Round 0 and who reported it; null when the ticket has no Round 0.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function reportPinsOf(TicketId $ticketId): ?ReportPins;

    /**
     * The tenant's check item with that id, locked until the transaction ends, so a second caller waits here and then
     * reads the state this one saves; null when there is none.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function lockedItem(CheckItemId $id): ?CheckItem;

    /**
     * Writes the item's state and who changed it when, over the state it was read at.
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the stored item is not the tenant's or
     *                         not at $item->savedState() any more (it was not read with lockedItem)
     */
    public function saveItem(CheckItem $item): void;
}
