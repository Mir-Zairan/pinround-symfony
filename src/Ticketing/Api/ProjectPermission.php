<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The voter attributes for projects (ADR-0013). ProjectVoter decides them.
 */
final class ProjectPermission
{
    /** Create a project owned by a team; the subject is that TeamId. The team's Lead, and an Admin of its organization. */
    public const string CREATE = 'PROJECT_CREATE';

    /** Unlink a project from the team that owns it; the subject is that TeamId. Only the team's Lead, not an Admin. */
    public const string UNLINK = 'PROJECT_UNLINK';

    /** Link a project with no team to a team; the subject is that TeamId. The team's Lead, and an Admin of its organization. */
    public const string LINK = 'PROJECT_LINK';
}
