<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\TeamPermission;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ProjectChoice;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The one choice of team for a tickets page's rail, the toolbar's people chips and "outside the team": the tickets
 * list and the whose-turn view both ask it, so they never disagree on the team.
 */
final readonly class RailTeamChoice
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    /**
     * The picked project's team when the viewer may see it (TeamPermission::VIEW); for "All projects", a project no
     * team owns or one of a team the viewer may not see, the team picked with TicketListContext::TEAM_QUERY, else the
     * viewer's first team by id.
     *
     * @return TeamId|null null for a viewer in no team whom the project's team, if any, is not shown to
     */
    public function teamOf(ActorContext $viewer, ?ProjectChoice $project, ?TeamId $pickedTeamId): ?TeamId
    {
        $projectsTeamId = $project?->teamId();
        if (null !== $projectsTeamId && $this->authorizationChecker->isGranted(TeamPermission::VIEW, $projectsTeamId)) {
            return $projectsTeamId;
        }

        return $pickedTeamId ?? $viewer->roles()->teamIds()[0] ?? null;
    }
}
