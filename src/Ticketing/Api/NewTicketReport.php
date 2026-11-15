<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The screenshot part of the new ticket form: one picture with its title, summary and pins, saved as the ticket's
 * report (Round 0). Review implements it, so Ticketing asks without depending on Review.
 *
 * $postedReport is the form's fields as posted, raw strings:
 * ['media_id' => string, 'title' => string, 'summary' => string, 'pins' => array<int|string, array{x?: string, y?: string, note?: string}>].
 * media_id '' is no screenshot, and then title and summary must be blank and pins empty; pins are keyed by their
 * number n of the fields pins[n][x], pins[n][y], pins[n][note], and a missing note is an empty one. A missing key, a
 * value that is not a string or array as above or not UTF-8, an x or y that is not a plain decimal number or more pins
 * than the report takes is refused with a plain message, never an exception of another kind.
 */
interface NewTicketReport
{
    /**
     * Whether the screenshot part can be saved, checked before the ticket is made, inside the caller's transaction whose
     * tenant is $organizationId: the form's shape and that the picture is one the reporter uploaded and is processed.
     * The title, summary and pin rules that need no ticket are left to attach.
     *
     * @param array<mixed> $postedReport see the interface
     *
     * @return string|null the reason to show under the screenshot field, or null when it is fine or no screenshot was
     *                     posted
     *
     * @throws \LogicException when a screenshot was posted and no transaction is open or the tenant is not
     *                         $organizationId
     */
    public function refusalOf(OrganizationId $organizationId, UserId $reporterId, array $postedReport): ?string;

    /**
     * Saves the screenshot part as the new ticket's report, right after the ticket is made in the same transaction;
     * does nothing when no screenshot was posted. Pins come in the order of their posted number and are numbered 1,
     * 2, … with no gap.
     *
     * @param array<mixed> $postedReport see the interface
     *
     * @throws NewTicketReportRefused when the report breaks a rule; nothing of it is saved, and the caller rolls back
     *                                the ticket and shows the message with the form as it was sent
     * @throws \LogicException        when no transaction is open or no tenant is set
     */
    public function attach(OrganizationId $organizationId, UserId $reporterId, TicketId $ticketId, array $postedReport, \DateTimeImmutable $reportedAt): void;
}
