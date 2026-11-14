<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the tickets of the current organization. Each command runs in the caller's transaction, begun under the
 * organization as tenant, and writes the ticket, its history row, its turn row and its outbox event there, so a
 * rollback leaves none of them. Who may run it is the voter's question, asked by the caller.
 */
interface TicketCommands
{
    /**
     * Reports a ticket in a project that a team owns. It takes the project's next number, so the project's row stays
     * locked until the caller's transaction ends. It starts in the team's To do step when it has an assignee, else in
     * Backlog, and it is visible to everyone in the organization.
     *
     * @param string $projectId a project's uuid, as the caller may not build Ticketing's ProjectId
     * @param string $type      bug, story or task
     * @param string $priority  blocker, critical, major, normal or minor
     *
     * @return string the new ticket's key, for example "SHOP-12"
     *
     * @throws \DomainException          when the organization has no project with that id, no team owns the project, or
     *                                   the assignee is not a lead or member of that team in the organization; nothing
     *                                   is saved
     * @throws \InvalidArgumentException when the title is blank or over 200 characters, the description over 50,000
     *                                   characters, or the type or priority unknown; the number taken is given back only
     *                                   when the caller rolls back
     * @throws \LogicException           when no transaction is open, or the team's steps lack Backlog or To do
     */
    public function create(
        OrganizationId $organizationId,
        UserId $reporter,
        string $projectId,
        string $title,
        string $description,
        string $type,
        string $priority,
        ?UserId $assignee,
        \DateTimeImmutable $reportedAt,
    ): string;

    /**
     * Moves the ticket with one of the Workflow verbs, where the team's transition policy sends it, and writes its
     * "moved" history row with the verb, its turn row and a TicketMoved event. A ticket moved into Needs input waits on
     * its reporter.
     *
     * @param string $key             the ticket's key, for example "SHOP-12"
     * @param string $verb            a Workflow verb's code, for example "start_work"
     * @param int    $expectedVersion the ticket's version the caller read
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it; nothing is saved
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first;
     *                                nothing of the move is saved and the ticket is not reloaded
     * @throws MoveRefused            when the ticket has no team, the policy refuses the verb to this person (with the
     *                                rule's note when one explains it), the target is not a step of the team, is the
     *                                ticket's step now, the verb is give_it_to (giveTo takes it), or it leads to Needs
     *                                input and the reporter is no member of the organization; nothing is saved
     * @throws \LogicException        when no transaction is open
     */
    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at): void;

    /**
     * "Give it to…", the give_it_to verb with its person: makes them the assignee, moving a Backlog ticket to To do in
     * the same save (a ticket in To do stays there), and writes the "moved" history row with the payload
     * {"verb": "give_it_to", "assignee_id": <the person>}, the turn row, TicketMoved (only for a ticket that left
     * Backlog) and TicketAssigned. A new assignee clears the stored reviewer, and the stored tester when it is the new
     * assignee. In To do only the lead gives an assigned ticket to someone else (Workflow's policy).
     *
     * @param UserId $assignee the person to give it to: the lead or a member of the ticket's team, not a viewer
     *
     * @throws TicketNotFound         as move does
     * @throws TicketChangedMeanwhile as move does
     * @throws MoveRefused            when the ticket has no team, the policy refuses give_it_to to this person (with the
     *                                rule's note when one explains it), the assignee is not the team's lead or a member
     *                                (with the note "Give it to someone in the ticket’s team."), or already has the ticket
     *                                (with the note "{assignee} already has this ticket."); nothing is saved
     * @throws \LogicException        when no transaction is open
     */
    public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): void;

    /**
     * The lead's "Move to another step…": moves the ticket to a step of its team without a verb, and writes the same rows
     * and event as move, with no verb. In Needs input the ticket waits on the person or label given, else its reporter.
     *
     * @param string $stepId the uuid of the step to move to
     *
     * @throws TicketNotFound         as move does
     * @throws TicketChangedMeanwhile as move does
     * @throws MoveRefused            when the ticket has no team, the step is not one of its team or is its step now, the
     *                                policy refuses the move (with the rule's note when one explains it), someone to
     *                                wait on is given for a step other than Needs input, or the person to wait on (the
     *                                reporter when nobody is named) is no member of the organization; nothing is saved
     * @throws WaitingOnRefused       when both a person and a label are given, or the label is blank, over 100
     *                                characters or not one line; nothing is saved
     * @throws \LogicException        when no transaction is open
     */
    public function moveTo(
        OrganizationId $organizationId,
        UserId $actor,
        string $key,
        string $stepId,
        int $expectedVersion,
        ?UserId $waitingOnUserId,
        ?string $waitingOnLabel,
        \DateTimeImmutable $at,
    ): void;
}
