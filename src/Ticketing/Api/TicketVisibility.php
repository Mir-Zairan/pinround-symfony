<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The one ticket visibility predicate (ADR-0013 Clarifications, data-model.md › Ticket visibility predicate) for one
 * viewer: a ticket is visible when it is for everyone, or in one of the viewer's teams, or has the viewer as one of its
 * six people, or the viewer is an admin, or it has no team and the viewer is the organization's triager.
 * isVisible() is the PHP check; TicketVisibilitySql renders the same rule as SQL. The caller builds it from
 * Organization\Api\ActorContext and the triager of the organization's settings, which this module may not read itself.
 */
final readonly class TicketVisibility
{
    /**
     * @param list<TeamId> $teamIds
     */
    private function __construct(private UserId $viewerId, private array $teamIds, private bool $isAdmin, private UserId $triagerId)
    {
    }

    /**
     * @param list<TeamId> $teamIds   the teams the viewer has any team role in: lead, member or viewer
     * @param bool         $isAdmin   whether the viewer has the org role Admin; the Manager role widens nothing
     * @param UserId       $triagerId the org setting triager_id, who sees restricted tickets with no team
     */
    public static function for(UserId $viewerId, array $teamIds, bool $isAdmin, UserId $triagerId): self
    {
        return new self($viewerId, $teamIds, $isAdmin, $triagerId);
    }

    public function isVisible(TicketVisibilityFacts $ticket): bool
    {
        return TicketVisibilityFacts::FOR_EVERYONE === $ticket->visibility
            || (null !== $ticket->teamId && $this->isInTeam($ticket->teamId))
            || array_any($ticket->involvedUserIds(), fn (UserId $person): bool => $person->equals($this->viewerId))
            || $this->isAdmin
            || (null === $ticket->teamId && $this->isTriager());
    }

    public function viewerId(): UserId
    {
        return $this->viewerId;
    }

    /**
     * @return list<TeamId>
     */
    public function teamIds(): array
    {
        return $this->teamIds;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isTriager(): bool
    {
        return $this->viewerId->equals($this->triagerId);
    }

    private function isInTeam(TeamId $teamId): bool
    {
        return array_any($this->teamIds, static fn (TeamId $ownTeamId): bool => $ownTeamId->equals($teamId));
    }
}
