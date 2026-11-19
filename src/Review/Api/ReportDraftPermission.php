<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes of a report draft (ADR-0013, ADR-0039); the subject is its ReportDraftView. ReportDraftVoter
 * decides them: its owner alone, in the owner's organization; never an admin or a lead.
 */
final class ReportDraftPermission
{
    /** See the draft and its pictures. */
    public const string VIEW = 'REPORT_DRAFT_VIEW';

    /** Change the draft's text and pictures, or delete it. */
    public const string EDIT = 'REPORT_DRAFT_EDIT';

    /** Make the ticket from it: only from a browser session, never with a personal access token. */
    public const string SEND = 'REPORT_DRAFT_SEND';
}
