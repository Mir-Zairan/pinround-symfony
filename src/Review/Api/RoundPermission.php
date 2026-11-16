<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes for rounds (ADR-0013, ADR-0017); RoundVoter decides all three.
 *
 * A caller answers a denied VIEW or EDIT with 404, the same as a round id RoundQueries::accessFactsOf does not know, so
 * another person's unshared draft cannot be told from a missing round.
 */
final class RoundPermission
{
    /** Start a private draft round; the subject is the ticket's TicketId. Its current assignee, while it is open. */
    public const string DRAFT_CREATE = 'ROUND_DRAFT_CREATE';

    /**
     * See a round; the subject is its RoundAccessFacts. An unshared draft its owner only, a shared round whoever
     * TICKET_VIEW lets see its ticket; a deleted draft nobody.
     */
    public const string VIEW = 'ROUND_VIEW';

    /**
     * Change a round; the subject is its RoundAccessFacts. An unshared draft its owner only, a shared round whoever
     * TICKET_VIEW and TICKET_EDIT let see and edit its ticket; a deleted draft nobody.
     */
    public const string EDIT = 'ROUND_EDIT';
}
