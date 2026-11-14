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
    /** @var array<string, string> by canonical team id */
    private array $teamSlugsById;

    /**
     * @param list<TeamId>          $leadTeamIds   the teams the user leads
     * @param list<TeamId>          $memberTeamIds the teams the user is a member of
     * @param list<TeamId>          $viewerTeamIds the teams the user is a viewer of
     * @param array<string, string> $teamSlugsById the slug of each of those teams, by team id in any case, so the
     *                                             menu links them without another query; a team left out has none
     *
     * @throws \InvalidArgumentException when a team is given twice, in one list or in two, or a key of $teamSlugsById
     *                                   is not a team id
     */
    public function __construct(
        private bool $isAdmin,
        private bool $isManager,
        private array $leadTeamIds,
        private array $memberTeamIds,
        private array $viewerTeamIds,
        array $teamSlugsById = [],
    ) {
        $teamIds = array_map(static fn (TeamId $teamId): string => $teamId->toString(), [...$leadTeamIds, ...$memberTeamIds, ...$viewerTeamIds]);
        if (\count(array_unique($teamIds)) !== \count($teamIds)) {
            throw new \InvalidArgumentException('A user has one team role per team.');
        }

        $slugsByCanonicalId = [];
        foreach ($teamSlugsById as $teamId => $slug) {
            $slugsByCanonicalId[new TeamId($teamId)->toString()] = $slug;
        }
        $this->teamSlugsById = $slugsByCanonicalId;
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
     * The role rule shared by "Ask a question" and "Ping a person" (personas-and-roles.md): an Admin, a Manager, a Lead
     * or Member of the ticket's team, or its reporter; a Viewer of the team is denied, also when they reported it. The
     * caller still checks that the user may see the ticket.
     *
     * @param ?TeamId $ticketTeamId the ticket's team, null while it has none
     * @param bool    $isReporter   whether this user reported the ticket
     */
    public function mayAskOrPingOn(?TeamId $ticketTeamId, bool $isReporter): bool
    {
        if ($this->isAdmin || $this->isManager) {
            return true;
        }
        if (null !== $ticketTeamId) {
            if ($this->isLeadOf($ticketTeamId) || $this->isMemberOf($ticketTeamId)) {
                return true;
            }
            // The matrix's "only as reporter" is the Other user column's; its Viewer column has no exception.
            if ($this->isViewerOf($ticketTeamId)) {
                return false;
            }
        }

        return $isReporter;
    }

    /**
     * The address part of a team the user has a role in; null when the team's slug was not given, as for a team the
     * user is not in.
     */
    public function teamSlugOf(TeamId $teamId): ?string
    {
        return $this->teamSlugsById[$teamId->toString()] ?? null;
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
