<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Domain\MovedFrom;

/**
 * What happened to a ticket, one row of tkt_ticket_event per change, for its activity. Rows are only ever added.
 */
interface TicketHistory
{
    /** The payload key of a give's "moved" row that names the person given the ticket; its verb is MoveTicket::GIVE_IT_TO. */
    public const string GIVEN_TO_PAYLOAD_KEY = 'assignee_id';
    /** The payload key of a move's "moved" row that keeps the fields it changed, as they were before it. */
    public const string MOVED_FROM_PAYLOAD_KEY = 'moved_from';

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
     * @param string         $fromStepId   the wf_step id the ticket left
     * @param string         $toStepId     the wf_step id the ticket is in now
     * @param string|null    $verb         the code of the Workflow verb the person clicked, for example "start_work", or null
     *                                     for a lead's "Move to another step…"
     * @param string|null    $overrideNote why a lead moved the ticket past a rule, or null when no rule was overridden
     * @param MovedFrom|null $movedFrom    the fields the move changed, as they were before it, kept under the payload
     *                                     key MOVED_FROM_PAYLOAD_KEY so lastMoveOf can give them to Undo; null for a
     *                                     move that cannot be undone (an Undo itself)
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
        ?MovedFrom $movedFrom = null,
    ): void;

    /**
     * The ticket's latest row of any type, under the caller's tenant: a move is undone only while it is the last thing
     * that happened to the ticket.
     *
     * @param \DateTimeImmutable $ticketCreatedAt bounds the read to the partitions from the ticket's creation on
     *
     * @return LastTicketChange|null null for a ticket with no row
     *
     * @throws \LogicException when no transaction is open
     */
    public function lastChangeOf(TicketId $ticketId, \DateTimeImmutable $ticketCreatedAt): ?LastTicketChange;

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

    /**
     * Adds a row of the change's kind in the caller's transaction, under the organization of its tenant, with no steps
     * and the payload {"outcome", "pin_number", "area_name"}, each label null when it is missing.
     *
     * @throws \LogicException when no transaction is open
     */
    public function recordReviewChange(OrganizationId $organizationId, TicketId $ticketId, UserId $actor, ReviewChange $change, \DateTimeImmutable $at): void;
}
