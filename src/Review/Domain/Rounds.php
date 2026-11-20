<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of tickets with their screenshots and pins. Each method needs an open transaction under the round's
 * organization as tenant, and reads or writes that organization's rounds only.
 */
interface Rounds
{
    public function nextIdentity(): RoundId;

    public function nextScreenshotIdentity(): ScreenshotId;

    public function nextPinIdentity(): PinId;

    /**
     * Writes the round with its screenshots and pins, all or nothing.
     *
     * @throws RoundSlotTaken  when the ticket has a round with the same number, or the owner an open draft on it
     * @throws \LogicException when no transaction is open, or the round id was added before (a bug or a redelivered
     *                         message, not a slot)
     */
    public function add(Round $round): void;

    /**
     * The tenant's round with that id, of any kind and state, deleted drafts too, with its screenshots and pins.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function get(RoundId $id): ?Round;

    /**
     * The owner's open unshared draft on the ticket, of which there is at most one.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function openUnsharedDraftOf(TicketId $ticketId, UserId $ownerId): ?Round;

    /**
     * The id, decision and screenshot count of the draft openUnsharedDraftOf gives, in one read; it neither loads the
     * draft's screenshots and pins nor holds anything.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function openUnsharedDraftFactsOf(TicketId $ticketId, UserId $ownerId): ?DraftFacts;

    /**
     * The number the ticket's next shared round gets: one above its highest, so 1 when it has only its report or
     * none. Holds the ticket's round numbers until the transaction ends, so a second caller waits here and then reads
     * past the number this one saves.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function nextFreeNumberOf(TicketId $ticketId): int;

    /**
     * The ticket's open shared rounds (Round::isOpenSharedRound) with their screenshots and pins, by number: at most
     * one under ADR-0048, more on a ticket from before it. Holds the ticket's round numbers as nextFreeNumberOf does,
     * then locks these rows, both until the transaction ends, so of two requests that check "no open round" and then
     * share, the second waits here and then sees the first one's share.
     *
     * @return list<Round>
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function openSharedRoundsOf(TicketId $ticketId): array;

    /**
     * Writes the changes of a round read with get() or added, over the version it was read at, all or nothing: the
     * round's number, share time, state, version, decision and deletion, and its screenshots and pins (new ones added,
     * changed ones updated, missing ones removed). A screenshot keeps its position and a pin its number; new ones
     * and their pins are stamped $savedAt. Afterwards the stored round is at the round's version, so a further change
     * of this object cannot be saved: read the round again first.
     *
     * @throws RoundChanged    when the stored round is not at $round->savedVersion() any more, or is not the tenant's;
     *                         nothing is written
     * @throws RoundSlotTaken  when the ticket has another round with the number the round now has; nothing is written
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function save(Round $round, \DateTimeImmutable $savedAt): void;
}
