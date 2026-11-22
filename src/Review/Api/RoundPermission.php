<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes for rounds (ADR-0013, ADR-0017); RoundVoter decides VIEW, EDIT, MOVE_TO_REPORT_DRAFT,
 * COPY_TO_OWN_DRAFT and ADD_GENERAL_NOTE, DraftRoundCreationVoter DRAFT_CREATE.
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

    /**
     * "Pin this in my draft": copy a screenshot of a shared round into one's own draft round on the ticket; the subject
     * is the shared round's RoundAccessFacts. Whoever may see the round and may start a draft on its ticket (DRAFT_CREATE),
     * except the round's owner, who retakes in a draft instead. Nobody's on an unshared or deleted draft.
     */
    public const string COPY_TO_OWN_DRAFT = 'ROUND_COPY_TO_OWN_DRAFT';

    /**
     * Add a general note to a shared round (GeneralNoteCommands, ADR-0048 note of 2026-11-22); the subject is its
     * RoundAccessFacts. Whoever may see the round and write in its ticket's thread: as ConversationPermission COMMENT,
     * whoever TicketPermission VIEW lets see the ticket, so exactly whoever VIEW grants on a shared round, which the round
     * page relies on to show the form without a second vote. Nobody's on an unshared or deleted draft. That the round is
     * open and no checklist is GeneralNoteCommands' rule, checked on the locked round.
     */
    public const string ADD_GENERAL_NOTE = 'ROUND_ADD_GENERAL_NOTE';
}
