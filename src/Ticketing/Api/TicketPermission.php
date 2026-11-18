<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The voter attributes for tickets (ADR-0013). TicketVoter decides VIEW, EDIT, MOVE, ASSIGN and CHOOSE_MAIN_SUMMARY on a ticket's
 * TicketVisibilityFacts; TicketCreationVoter decides CREATE and ASSIGN on the TeamId the new ticket is for.
 */
final class TicketPermission
{
    /** See a ticket; the subject is its TicketVisibilityFacts. Whoever the ticket visibility predicate lets see it. */
    public const string VIEW = 'TICKET_VIEW';

    /** Create a ticket for a team on the team ticket form; the subject is that TeamId. Its Lead, Member or Viewer. */
    public const string CREATE = 'TICKET_CREATE';

    /** Change a ticket's fields; the subject is its TicketVisibilityFacts. A Lead or Member of the ticket's team. */
    public const string EDIT = 'TICKET_EDIT';

    /**
     * Move a ticket with a verb; the subject is its TicketVisibilityFacts. A Lead or Member of the ticket's team; which
     * verb a Member may use is the workflow rule's to decide, not this attribute's.
     */
    public const string MOVE = 'TICKET_MOVE';

    /** Give a ticket to someone; the subject is its TicketVisibilityFacts. Only a Lead of the ticket's team. */
    public const string ASSIGN = 'TICKET_ASSIGN';

    /**
     * Press "Use as main summary" on the ticket's proposal; the subject is its TicketVisibilityFacts. A Lead of the
     * ticket's team or its reporter, on a ticket with no team its reporter only.
     */
    public const string CHOOSE_MAIN_SUMMARY = 'TICKET_CHOOSE_MAIN_SUMMARY';
}
