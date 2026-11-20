<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The voter attributes of a notebook (ADR-0013, ADR-0046); the subject is its NotebookView. NotebookVoter decides them:
 * its owner alone, in the notebook's organization; never an admin, a lead or the team.
 */
final class NotebookPermission
{
    /** See the notebook, its pictures, pins and scenarios. */
    public const string VIEW = 'NOTEBOOK_VIEW';

    /** Rename it, add a picture, replace its scenarios, or delete it. */
    public const string EDIT = 'NOTEBOOK_EDIT';
}
