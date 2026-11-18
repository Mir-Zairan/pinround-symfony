<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * "Summarize with AI" for the ticket page: queue a summary job for a person and read how the latest one is doing. The
 * AI runs in worker-heavy (async_ai); no web request waits on it. An answered job becomes the ticket's current summary
 * through Ticketing; a failed one leaves a failed request with its line until it expires or a new request replaces it.
 */
interface Summaries
{
    /**
     * Decides the organization's AI policy for the person and ticket as the job's gateway call will, and whether the
     * person has an AI call left without counting one, then queues the job. Reads under row-level security, so it needs
     * an open transaction whose tenant is $organizationId. Queueing does not wait for that transaction: the job is sent
     * at once and reads only committed rows.
     *
     * @throws SummaryRequestRefused when the policy says no (the person is no member, cannot see the ticket, AI is off
     *                               or for leads only, or the ticket is team-only and kept from cloud AI), the person
     *                               has no AI call left this hour, or a summary of the ticket is already queued;
     *                               nothing is queued then
     * @throws \RuntimeException     when the request state or the queue cannot be reached; the request is not kept
     *                               then, so the person may try again
     * @throws \Throwable            the call limit's storage error when it cannot be read; nothing is queued then
     */
    public function request(TicketId $ticketId, UserId $requesterId, OrganizationId $organizationId): void;

    /**
     * The latest request of the ticket that is queued or failed. Checks no access: the caller must already have
     * decided Ticketing's TicketPermission::VIEW on the ticket for the viewer, as the ticket page's summary frame does
     * on each poll. Reads only the request state, no database row.
     *
     * @return SummaryRequestView|null null when no request is queued or failed, or the summary was stored
     */
    public function latestRequestOfVisibleTicket(TicketId $ticketId, OrganizationId $organizationId): ?SummaryRequestView;
}
