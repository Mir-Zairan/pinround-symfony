<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the tickets of the current organization. Each command runs in the caller's transaction, begun under the
 * organization as tenant, and writes the ticket, its history row, its turn row, its outbox event and, for a move, the
 * actor's cleared pings there, so a rollback leaves none of them. Who may run it is the voter's question, asked by the caller.
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
     * Reports a problem from Report a problem (P06.T1, FR-INT-01 to FR-INT-09): the ticket is made at once. Its check
     * reasons come from the words and the report's facts (Intake's CheckReasons), and a security word makes it
     * team-only. It starts in To do with the picked person as assignee, else in Backlog. With no team ("Not sure") it
     * has no team and no assignee, and takes the organization's core steps through the project's team. The project's
     * row stays locked until the caller's transaction ends, as create does.
     *
     * @throws \DomainException          when the organization has no such project, no team owns it, the project is not
     *                                   the picked team's, the assignee is not a lead or member of the team, or the
     *                                   reporter picked a person in a team they are not in; nothing is saved
     * @throws \InvalidArgumentException as create throws it, also for a person with no team, and for more than three
     *                                   duplicate keys
     * @throws \LogicException           when no transaction is open, or the team's steps lack Backlog or To do
     */
    public function report(ProblemReport $report): ReportedTicket;

    /**
     * Moves the ticket with one of the Workflow verbs, where the team's transition policy sends it, and writes its
     * "moved" history row with the verb, its turn row and a TicketMoved event. A ticket moved into Needs input waits on
     * its reporter.
     *
     * When the actor has an undecided draft round on the ticket with a screenshot (ShareDraftQuestion), the move then
     * shares it or keeps it private as $draftChoice says, in the same transaction; nobody else's draft is touched. A
     * caller that does not ask the person, such as an API client, keeps the default: private (ADR-0017).
     *
     * A move-on verb (not lead-only) closes, in the same transaction and before that share, the ticket's open shared
     * round when it was the actor's turn, and a findings round also when the actor is the assignee, but not a round the
     * actor shared outside a move (ADR-0048). The share in the same move is then numbered after the close.
     *
     * @param string           $key             the ticket's key, for example "SHOP-12"
     * @param string           $verb            a Workflow verb's code, for example "start_work"
     * @param int              $expectedVersion the ticket's version the caller read
     * @param DraftChoice|null $draftChoice     null when the person has not chosen yet, so an undecided draft stops
     *                                          the move with DraftDecisionNeeded
     *
     * @return MoveResult the actor's open pings on the ticket the move cleared in the same transaction (FR-PNG-05; 0 when
     *                    the organization's auto-clear is off or none were open), and the draft round's outcome: shared
     *                    with the number it got, kept private, or none when there was no undecided draft
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it; nothing is saved
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first;
     *                                nothing of the move is saved and the ticket is not reloaded. Also when another
     *                                request changed the actor's draft while the move shared it or kept it private: the
     *                                move is written by then, so the caller rolls back its whole transaction
     * @throws MoveRefused            when the ticket has no team, the policy refuses the verb to this person (with the
     *                                rule's note when one explains it), the target is not a step of the team, is the
     *                                ticket's step now, the verb is give_it_to (giveTo takes it), or it leads to Needs
     *                                input and the reporter is no member of the organization; nothing is saved. Also when
     *                                $draftChoice is Share and the share must wait (ADR-0048, one open shared round per
     *                                ticket): as MoveRefused::roundStillOpen while a shared round stays open after the
     *                                move's close, with its number and, for a findings round, the assignee whose verdict
     *                                it waits for, so the caller names that person or whoever's turn it is; and with
     *                                Review's own reason when the draft has no screenshot left. The move is written by
     *                                then, so the caller rolls back, and reads whose turn it is only after the rollback
     * @throws DraftDecisionNeeded    when $draftChoice is null and the actor has an undecided draft with a screenshot on
     *                                the ticket, after every check above but the save; nothing is saved. Its draft carries
     *                                the round that stays open, if any, so the question offers only keeping it private
     * @throws \LogicException        when no transaction is open
     */
    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult;

    /**
     * "Give it to…", the give_it_to verb with its person: makes them the assignee, moving a Backlog ticket to To do in
     * the same save (a ticket in To do stays there), and writes the "moved" history row with the payload
     * {"verb": "give_it_to", "assignee_id": <the person>}, the turn row, TicketMoved (only for a ticket that left
     * Backlog) and TicketAssigned. A new assignee clears the stored reviewer, and the stored tester when it is the new
     * assignee. In To do only the lead gives an assigned ticket to someone else (Workflow's policy). It never asks about
     * or decides the actor's draft round: that question belongs to moves on to another step (ADR-0017, P05.T9).
     *
     * @param UserId $assignee the person to give it to: the lead or a member of the ticket's team, not a viewer
     *
     * @return int how many of the actor's open pings on the ticket it cleared, as move does
     *
     * @throws TicketNotFound         as move does
     * @throws TicketChangedMeanwhile as move does
     * @throws MoveRefused            when the ticket has no team, the policy refuses give_it_to to this person (with the
     *                                rule's note when one explains it), the assignee is not the team's lead or a member
     *                                (with the note "Give it to someone in the ticket’s team."), or already has the ticket
     *                                (with the note "{assignee} already has this ticket."); nothing is saved
     * @throws \LogicException        when no transaction is open
     */
    public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): int;

    /**
     * The lead's "Move to another step…": moves the ticket to a step of its team without a verb, and writes the same rows
     * and event as move, with no verb. In Needs input the ticket waits on the person or label given, else its reporter.
     * It shares or keeps private the actor's undecided draft round as move does. It is lead-only, so it closes no round.
     *
     * @param string           $stepId      the uuid of the step to move to
     * @param DraftChoice|null $draftChoice as move takes it
     *
     * @return MoveResult as move returns it
     *
     * @throws TicketNotFound         as move does
     * @throws TicketChangedMeanwhile as move does, also for the actor's draft
     * @throws MoveRefused            when the ticket has no team, the step is not one of its team or is its step now, the
     *                                policy refuses the move (with the rule's note when one explains it), someone to
     *                                wait on is given for a step other than Needs input, or the person to wait on (the
     *                                reporter when nobody is named) is no member of the organization; nothing is saved.
     *                                Also for a share that must wait, as move does; being lead-only, this move closes no
     *                                round first, so every open shared round holds the share back but a findings round
     *                                that the assignee's own share closes
     * @throws WaitingOnRefused       when both a person and a label are given, or the label is blank, over 100
     *                                characters or not one line; nothing is saved
     * @throws DraftDecisionNeeded    as move does, after every check above but the save; nothing is saved
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
        ?DraftChoice $draftChoice = DraftChoice::Keep,
    ): MoveResult;
}
