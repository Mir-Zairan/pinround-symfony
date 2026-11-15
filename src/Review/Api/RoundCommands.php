<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the rounds of a ticket. Each command runs in the caller's open transaction, whose tenant is the ticket's
 * organization, so it commits or rolls back with the caller's own writes.
 */
interface RoundCommands
{
    /** The most pins one screenshot takes. */
    public const int MAX_PINS_PER_SCREENSHOT = 100;

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
}
