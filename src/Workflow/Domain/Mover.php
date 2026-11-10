<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * Who may use a verb, as the "Who may" column of the transition catalogue names them. Rules can still block a mover
 * (a guard); this only says who is asked at all.
 */
enum Mover: string
{
    /** The lead of the ticket's team. */
    case Lead = 'lead';

    /** The ticket's assignee. */
    case Assignee = 'assignee';

    /** The lead of the ticket's team, but only while the ticket has no assignee. */
    case LeadWhenUnassigned = 'lead_when_unassigned';

    /**
     * The person a ticket in Needs input waits on: the user it waits on, else the reporter while no label (a customer,
     * a supplier) names someone outside the system.
     */
    case WaitedOn = 'waited_on';

    /** The person checking a new ticket, while that check is needed. */
    case Checker = 'checker';

    /** The ticket's reviewer, whose turn it is in code review: the stored one, else the one the reviewer choice finds. */
    case Reviewer = 'reviewer';

    /**
     * The ticket's tester, whose turn it is in testing (the stored one, else the one the tester choice finds), or any
     * team member with the job tester.
     */
    case Tester = 'tester';

    /**
     * A tester as Tester names them, but only while the ticket is in a company or team step of the Checking group,
     * where the turn is theirs too.
     */
    case TesterInCheckingStep = 'tester_in_checking_step';
}
