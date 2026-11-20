<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes for rounds (ADR-0013, ADR-0017); RoundVoter decides VIEW, EDIT and MOVE_TO_REPORT_DRAFT,
 * DraftRoundCreationVoter DRAFT_CREATE.
 *
 * A caller answers a denied VIEW, EDIT or MOVE_TO_REPORT_DRAFT with 404, the same as a round id RoundQueries::accessFactsOf does not know, so
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

    /**
     * "Make it a new ticket": move a draft's pictures into a new report draft (ADR-0044); the subject is its
     * RoundAccessFacts. Its owner only, and only while it is unshared and not deleted.
     */
    public const string MOVE_TO_REPORT_DRAFT = 'ROUND_MOVE_TO_REPORT_DRAFT';
}
