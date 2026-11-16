<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes for the items of a checklist round (ADR-0013, FR-RND-04); CheckItemVoter decides them. The
 * subject is the item's CheckItemAccessFacts. Both need TICKET_VIEW and TICKET_EDIT on the item's ticket; the lead, a
 * Manager and an Admin with neither role are denied. A caller answers a denial with 404 when TICKET_VIEW is denied too,
 * and 403 otherwise.
 */
final class ChecklistPermission
{
    /** Mark an item fixed: the ticket's assignee only. */
    public const string MARK_FIXED = 'CHECKLIST_MARK_FIXED';

    /**
     * Confirm an item OK or send it back as not fixed: the ticket's tester, or any tester of its team while the ticket
     * has none stored. An item not marked fixed is the command's refusal to give, not a denial.
     */
    public const string CHECK = 'CHECKLIST_CHECK';
}
