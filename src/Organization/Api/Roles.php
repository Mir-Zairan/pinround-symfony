<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The org roles and team roles of one user in one organization (personas-and-roles.md, Role model). Org roles add
 * up; in each team the user has at most one team role: lead, member or viewer.
 */
final readonly class Roles
{
    /**
     * @param list<TeamId> $leadTeamIds   the teams the user leads
     * @param list<TeamId> $memberTeamIds the teams the user is a member of
     * @param list<TeamId> $viewerTeamIds the teams the user is a viewer of
     *
     * @throws \InvalidArgumentException when a team is given twice, in one list or in two
     */
    public function __construct(
        private bool $isAdmin,
        private bool $isManager,
        private array $leadTeamIds,
        private array $memberTeamIds,
        private array $viewerTeamIds,
    ) {
        $teamIds = array_map(static fn (TeamId $teamId): string => $teamId->toString(), [...$leadTeamIds, ...$memberTeamIds, ...$viewerTeamIds]);
        if (\count(array_unique($teamIds)) !== \count($teamIds)) {
            throw new \InvalidArgumentException('A user has one team role per team.');
        }
    }

    public static function none(): self
    {
        return new self(false, false, [], [], []);
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
     * True for the team role member only, not for lead or viewer.
     */
    public function isMemberOf(TeamId $teamId): bool
    {
        return self::contains($this->memberTeamIds, $teamId);
    }

    public function isViewerOf(TeamId $teamId): bool
    {
        return self::contains($this->viewerTeamIds, $teamId);
    }

    /**
     * True for any team role: lead, member or viewer.
     */
    public function isInTeam(TeamId $teamId): bool
    {
        return $this->isLeadOf($teamId) || $this->isMemberOf($teamId) || $this->isViewerOf($teamId);
    }

    /**
     * @return list<TeamId> the teams the user has any role in, in id order
     */
    public function teamIds(): array
    {
        $teamIds = [...$this->leadTeamIds, ...$this->memberTeamIds, ...$this->viewerTeamIds];
        usort($teamIds, static fn (TeamId $first, TeamId $second): int => $first->toString() <=> $second->toString());

        return $teamIds;
    }

    /**
     * @param list<TeamId> $teamIds
     */
    private static function contains(array $teamIds, TeamId $teamId): bool
    {
        return array_any($teamIds, static fn ($candidate) => $candidate->equals($teamId));
    }
}
