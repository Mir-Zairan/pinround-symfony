<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;

/**
 * What a person's team role and job in the ticket's team let them do with moves (personas-and-roles.md). Org roles
 * count for nothing here.
 */
final readonly class MoveRights
{
    public function leadsTheTeam(ActorInTeam $actor): bool
    {
        return ActorInTeam::TEAM_ROLE_LEAD === $actor->teamRole;
    }

    /**
     * True for the team roles lead and member; a viewer reads and comments only.
     */
    public function movesTickets(ActorInTeam $actor): bool
    {
        return ActorInTeam::TEAM_ROLE_LEAD === $actor->teamRole || ActorInTeam::TEAM_ROLE_MEMBER === $actor->teamRole;
    }

    public function isTester(ActorInTeam $actor): bool
    {
        return ActorInTeam::JOB_TESTER === $actor->job;
    }
}
