<?php

declare(strict_types=1);

namespace App\Web\Domain;

/**
 * The Home focus chips (FR-HOME-08): each narrows Home to its part. The values are stored in web_home_focus.
 */
enum HomeFocus: string
{
    case All = 'all';
    case MyWork = 'my_work';
    case MyTeam = 'my_team';
    case AllTeams = 'all_teams';
    case Admin = 'admin';
}
