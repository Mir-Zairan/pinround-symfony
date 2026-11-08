<?php

declare(strict_types=1);

namespace App\Web\Application;

use App\Organization\Api\Roles;
use App\Web\Domain\HomeFocus;

/**
 * Which Home focus chips a person gets (FR-HOME-08). It lives here, not on HomeFocus, because the Domain layer may not
 * see Organization's Roles.
 */
final class HomeFocusChoices
{
    /**
     * @return non-empty-list<HomeFocus> in display order: All, My work and My team always, then All teams for a
     *                                   Manager and Admin for an Admin
     */
    public static function offeredTo(Roles $roles): array
    {
        $choices = [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam];
        if ($roles->isManager()) {
            $choices[] = HomeFocus::AllTeams;
        }
        if ($roles->isAdmin()) {
            $choices[] = HomeFocus::Admin;
        }

        return $choices;
    }

    public static function isOfferedTo(HomeFocus $focus, Roles $roles): bool
    {
        return \in_array($focus, self::offeredTo($roles), true);
    }
}
