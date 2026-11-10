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
}
