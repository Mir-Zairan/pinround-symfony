<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attribute for verdicts in a review round (ADR-0013); VerdictVoter decides it.
 */
final class VerdictPermission
{
    /**
     * Give or undo a verdict on a pin or a screen area of a round; the subject is its RoundAccessFacts. The ticket's
     * tester or a tester of its team, and the lead of its team, while TICKET_VIEW and TICKET_EDIT let them see and edit
     * the ticket; nobody on an unshared or deleted draft. A caller answers a denial with 404 when RoundPermission VIEW
     * is denied too, and 403 otherwise.
     */
    public const string SET = 'ROUND_VERDICT';
}
