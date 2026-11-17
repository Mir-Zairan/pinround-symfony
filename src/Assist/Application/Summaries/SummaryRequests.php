<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

use App\Assist\Api\SummaryRequestView;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The state of each ticket's latest "Summarize with AI" request: queued while its job waits or runs, failed with its
 * line after the job gave up, and none once the summary is stored. One state per ticket, whoever asked; a queued
 * state ends on its own after an hour and a failed one after a day, so a lost job never blocks the button for good.
 */
interface SummaryRequests
{
    /**
     * Marks the ticket queued for the requester, as of now, unless it already is; the check and the write are one step,
     * so of two requests at once only one claims. A failed state is replaced.
     *
     * @return bool false when the ticket was already queued; nothing changed then
     *
     * @throws \RuntimeException when the state cannot be reached
     */
    public function claim(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool;

    /**
     * Replaces the ticket's state with failed, as of now, with the line to show.
     *
     * @throws \RuntimeException when the state cannot be reached
     */
    public function recordFailure(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId, string $sentence): void;

    /**
     * Forgets the ticket's state: its summary was stored, or its job could not be queued.
     *
     * @throws \RuntimeException when the state cannot be reached
     */
    public function release(OrganizationId $organizationId, TicketId $ticketId): void;

    /**
     * @throws \RuntimeException when the state cannot be reached or holds a value this class did not write
     */
    public function latestOf(OrganizationId $organizationId, TicketId $ticketId): ?SummaryRequestView;
}
