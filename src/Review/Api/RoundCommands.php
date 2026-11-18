<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the rounds of a ticket. Each command runs in the caller's open transaction, whose tenant is the ticket's
 * organization, so it commits or rolls back with the caller's own writes.
 *
 * The draft commands (ADR-0017) work on the owner's own draft only: an unknown or malformed round id, another
 * organization's round, another person's draft, a round that is not a draft and a deleted draft are all refused with
 * RoundRefused::NOT_FOUND, before anything else is checked except setDraftPins' pin cap, which comes first so an
 * oversized list is refused before any read. Who may start a draft at all (the current assignee of an
 * open ticket) is the caller's voter's to decide. Each one either saves the whole change or nothing.
 */
interface RoundCommands
{
    /** The most pins one screenshot takes. */
    public const int MAX_PINS_PER_SCREENSHOT = 100;

    /** The verdicts setDraftVerdict takes: "Looks right" and "Not right yet". */
    public const string VERDICT_LOOKS_RIGHT = 'looks_right';
    public const string VERDICT_NOT_RIGHT_YET = 'not_right';

    /**
     * Round 0 of a new ticket (P06): the reporter's screenshots in the order given, each with its pins numbered from 1.
     * Each picture must be one the reporter uploaded in the tenant's organization and be processed (Media's
     * UploadedMedia), so nobody can show another person's picture in their report.
     *
     * @param list<ScreenshotWithPins> $screenshots from 1 to 50
     * @param UserId                   $reporterId  the ticket's reporter, who owns the round and authored the pins
     *
     * @return string the new round's id
     *
     * @throws \DomainException Ticketing's TicketNotFound when the tenant has no ticket with that id; nothing is saved
     * @throws RoundRefused     when the reporter is not the ticket's, there is no screenshot or more than 50, a media
     *                          id is not of a picture the reporter uploaded (MEDIA_NOT_UPLOADED_BY_YOU, a malformed one
     *                          too) or its picture is still processing (MEDIA_STILL_PROCESSING) or was rejected
     *                          (MEDIA_REJECTED), a title is over 120 or a summary over 600 characters, a pin sits
     *                          outside the picture or its note is over 1,000 characters, a screenshot has more than
     *                          MAX_PINS_PER_SCREENSHOT pins, or the ticket has a report already; nothing is saved
     * @throws \LogicException  when no transaction is open or no tenant is set; nothing is saved
     */
    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string;

    /**
     * An empty private draft round of $ownerId on the ticket.
     *
     * @return string the new draft's id
     *
     * @throws \DomainException Ticketing's TicketNotFound when the tenant has no ticket with that id; nothing is saved
     * @throws RoundRefused     DRAFT_EXISTS when the owner has an open draft on the ticket; nothing is saved
     * @throws \LogicException  when no transaction is open or no tenant is set
     */
    public function startDraft(TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): string;

    /**
     * Adds a picture after the draft's last one, with no title, summary, pins or verdict.
     *
     * @param string $mediaId the id Uploads::complete returned for a picture $ownerId uploaded
     *
     * @return string the new screenshot's id
     *
     * @throws RoundRefused    NOT_FOUND; a MEDIA_ refusal as createReport's; DRAFT_CHANGED; or when the draft is
     *                         shared or holds 50 screenshots already; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function addDraftScreenshot(string $roundId, UserId $ownerId, string $mediaId, \DateTimeImmutable $addedAt): string;

    /**
     * Removes the picture with its pins; the others keep their places.
     *
     * @throws RoundRefused    NOT_FOUND, SCREENSHOT_NOT_FOUND, DRAFT_CHANGED, or when the draft is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function removeDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, \DateTimeImmutable $at): void;

    /**
     * Sets the picture's title (at most 120 characters) and summary (at most 600), trimmed; a blank one is none.
     *
     * @throws RoundRefused    NOT_FOUND, SCREENSHOT_NOT_FOUND, DRAFT_CHANGED, a text over its cap, or when the draft
     *                         is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void;

    /**
     * Puts $pins on the picture in place of the pins it had, numbered 1, 2, … in this order, authored by $ownerId.
     *
     * @param list<PlacedPin> $pins at most MAX_PINS_PER_SCREENSHOT
     *
     * @throws RoundRefused    when there are more than MAX_PINS_PER_SCREENSHOT pins (before anything is read),
     *                         NOT_FOUND, SCREENSHOT_NOT_FOUND, DRAFT_CHANGED, a pin outside the picture or a note over
     *                         1,000 characters, or when the draft is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function setDraftPins(string $roundId, UserId $ownerId, string $screenshotId, array $pins, \DateTimeImmutable $at): void;

    /**
     * Sets the owner's verdict on the picture, or clears it with null.
     *
     * @param string|null $verdict VERDICT_LOOKS_RIGHT, VERDICT_NOT_RIGHT_YET or null
     *
     * @throws RoundRefused    UNKNOWN_VERDICT, NOT_FOUND, SCREENSHOT_NOT_FOUND, DRAFT_CHANGED, or when the draft is
     *                         shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void;

    /**
     * Sets the owner's verdict on the picture and, unless $summary is null, its summary (at most 600 characters,
     * trimmed; a blank one is none), in one save over the version the draft was read at: the title stays the one
     * stored, so a title saved by another request in between is never put back, as that save is refused as
     * DRAFT_CHANGED instead. The draft's version goes up by one for the verdict and one more for a summary, also when
     * either is the one stored already.
     *
     * @param string      $verdict VERDICT_LOOKS_RIGHT or VERDICT_NOT_RIGHT_YET
     * @param string|null $summary null keeps the summary there is
     *
     * @throws RoundRefused    NOT_FOUND or SCREENSHOT_NOT_FOUND (isNotFound); UNKNOWN_VERDICT or a summary over its
     *                         cap (isInvalidContent); a shared or closed draft (isReadOnly); DRAFT_CHANGED; nothing is
     *                         saved, the verdict neither
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function setDraftVerdictWithSummary(string $roundId, UserId $ownerId, string $screenshotId, string $verdict, ?string $summary, \DateTimeImmutable $at): void;

    /**
     * Shows the draft to everyone who may see the ticket as the ticket's next round number. Holds the ticket's round
     * numbers until the caller's transaction ends, so two shares on one ticket get two numbers.
     *
     * @return int the round's number, from 1
     *
     * @throws RoundRefused    NOT_FOUND, DRAFT_CHANGED, NUMBER_TAKEN, or when the draft is shared already; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function shareDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int;

    /**
     * Shares the draft as shareDraft does and records the owner's hand-off choice "shared" (ADR-0017, P05.T9) in the
     * same save.
     *
     * @return int the round's number, from 1
     *
     * @throws RoundRefused    NOT_FOUND, DRAFT_CHANGED, NUMBER_TAKEN, or when the draft is shared or decided already;
     *                         nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function shareDraftAtHandOff(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int;

    /**
     * Deletes the unshared draft: it closes for good, no one sees it any more, and its owner may start a new one on
     * the ticket. Its pictures are left for retention to remove.
     *
     * @throws RoundRefused    NOT_FOUND, DRAFT_CHANGED, or when the draft is shared; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function deleteDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $deletedAt): void;
}
