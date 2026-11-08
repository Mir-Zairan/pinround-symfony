<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The org roles and team roles of one user in one organization (personas-and-roles.md, Role model). Roles add up;
 * a team role is one of lead, member or viewer.
 */
final readonly class Roles
{
    /**
     * @param list<TeamId> $teamIds     the teams the user has any role in
     * @param list<TeamId> $leadTeamIds the teams the user leads, each also in $teamIds
     *
     * @throws \InvalidArgumentException when a led team is not among $teamIds
     */
    public function __construct(
        private bool $isAdmin,
        private bool $isManager,
        private array $teamIds,
        private array $leadTeamIds,
    ) {
        foreach ($leadTeamIds as $leadTeamId) {
            if (!self::contains($teamIds, $leadTeamId)) {
                throw new \InvalidArgumentException('A team the user leads must be one of their teams.');
            }
        }
    }

    public static function none(): self
    {
        return new self(false, false, [], []);
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isManager(): bool
    {
        return $this->isManager;
    }

    public function isLeadOf(TeamId $teamId): bool
    {
        return self::contains($this->leadTeamIds, $teamId);
    }

    /**
     * True for any team role: lead, member or viewer.
     */
    public function isInTeam(TeamId $teamId): bool
    {
        return self::contains($this->teamIds, $teamId);
    }

    /**
     * @return list<TeamId> in the order given
     */
    public function teamIds(): array
    {
        return $this->teamIds;
    }

    /**
     * @param list<TeamId> $teamIds
     */
    private static function contains(array $teamIds, TeamId $teamId): bool
    {
        foreach ($teamIds as $candidate) {
            if ($candidate->equals($teamId)) {
                return true;
            }
        }

        return false;
    }
}
