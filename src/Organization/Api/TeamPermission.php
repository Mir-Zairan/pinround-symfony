<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * The voter attributes for teams (ADR-0013). TeamVoter decides them; the subject of each is the TeamId.
 */
final class TeamPermission
{
    /** See the team: anyone with a team role in it, and an Admin or Manager of its organization. */
    public const string VIEW = 'TEAM_VIEW';

    /** Do the work of the team's lead: only its Lead, whatever the org roles. */
    public const string LEAD = 'TEAM_LEAD';
}
