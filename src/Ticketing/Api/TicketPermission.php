<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The voter attributes for tickets (ADR-0013). TicketVoter decides VIEW, EDIT, MOVE and ASSIGN on a ticket's
 * TicketVisibilityFacts; TicketCreationVoter decides CREATE on the TeamId the new ticket is for.
 */
final class TicketPermission
{
    /** See a ticket; the subject is its TicketVisibilityFacts. Whoever the ticket visibility predicate lets see it. */
    public const string VIEW = 'TICKET_VIEW';

    /** Report a problem to a team; the subject is that TeamId. Every user of the team's organization, any role or none. */
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
}
