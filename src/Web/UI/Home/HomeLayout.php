<?php

declare(strict_types=1);

namespace App\Web\UI\Home;

use App\Organization\Api\Roles;

/**
 * The three Homes (home-work.md, home-manager.md, home-admin.md). Extra roles add parts to the work Home; only a
 * Manager or Admin with no lead or member role gets a Home of their own.
 */
enum HomeLayout
{
    case Work;
    case Manager;
    case Admin;

    public static function of(Roles $roles): self
    {
        $teamIds = $roles->teamIds();
        $worksInATeam = array_any($teamIds, static fn ($teamId): bool => $roles->isLeadOf($teamId) || $roles->isMemberOf($teamId));
        if ($worksInATeam) {
            return self::Work;
        }
        if ($roles->isManager()) {
            return self::Manager;
        }

        // An admin who is only a viewer somewhere keeps the work Home, whose Admin chip serves them.
        return $roles->isAdmin() && [] === $teamIds ? self::Admin : self::Work;
    }
}
