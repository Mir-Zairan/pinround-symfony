<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * What happened to a ticket, one row of tkt_ticket_event per change, for its activity. Rows are only ever added.
 */
interface TicketHistory
{
    /**
     * Adds the "created" row in the caller's transaction; the ticket must be saved before, as the row points at it.
     *
     * @param string $stepId the wf_step id the ticket starts in
     *
     * @throws \LogicException when no transaction is open
     */
    public function recordCreated(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, string $stepId, \DateTimeImmutable $at): void;

    /**
     * Adds the "moved" row in the caller's transaction, under the organization of its tenant.
     *
     * @param string      $fromStepId   the wf_step id the ticket left
     * @param string      $toStepId     the wf_step id the ticket is in now
     * @param string|null $verb         the code of the Workflow verb the person clicked, for example "start_work", or null
     *                                  for a lead's "Move to another step…"
     * @param string|null $overrideNote why a lead moved the ticket past a rule, or null when no rule was overridden
     *
     * @throws \LogicException when no transaction is open
     */
    public function recordMoved(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        ?string $verb,
        ?string $overrideNote,
        \DateTimeImmutable $at,
    ): void;

    /**
     * Adds the "moved" row of a "Give it to…" in the caller's transaction, under the organization of its tenant, with the
     * payload {"verb": "give_it_to", "assignee_id": <the person>}.
     *
     * @param string $fromStepId the wf_step id the ticket left; a give in To do leaves and enters the same step
     * @param string $toStepId   the wf_step id the ticket is in now
     *
     * @throws \LogicException when no transaction is open
     */
    public function recordGiven(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        UserId $assignee,
        \DateTimeImmutable $at,
    ): void;
}
