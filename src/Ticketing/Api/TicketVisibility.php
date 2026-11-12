<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The one ticket visibility predicate (ADR-0013 Clarifications, data-model.md › Ticket visibility predicate) for one
 * viewer in one organization: a ticket of that organization is visible when it is for everyone, or in one of the
 * viewer's teams, or has the viewer as one of its six people, or the viewer is an admin, or it has no team and the
 * viewer is the organization's triager. A ticket of another organization is never visible, not even to an admin.
 * isVisible() is the PHP check; TicketVisibilitySql renders the same rule as SQL. Get a viewer's one from
 * TicketVisibilities, which reads their roles and the triager from Organization, which this Api may not depend on.
 */
final readonly class TicketVisibility
{
    /**
     * @param list<TeamId> $teamIds
     */
    private function __construct(
        private OrganizationId $organizationId,
        private UserId $viewerId,
        private array $teamIds,
        private bool $isAdmin,
        private ?UserId $triagerId,
    ) {
    }

    /**
     * @param OrganizationId $organizationId the viewer's organization, the only one whose tickets can be visible
     * @param list<TeamId>   $teamIds        the teams the viewer has any team role in: lead, member or viewer
     * @param bool           $isAdmin        whether the viewer has the org role Admin; the Manager role widens nothing
     * @param ?UserId        $triagerId      the org setting triager_id, who sees restricted tickets with no team; null
     *                                       when the organization has none, so nobody sees them as triager
     */
    public static function for(OrganizationId $organizationId, UserId $viewerId, array $teamIds, bool $isAdmin, ?UserId $triagerId): self
    {
        return new self($organizationId, $viewerId, $teamIds, $isAdmin, $triagerId);
    }

    public function isVisible(TicketVisibilityFacts $ticket): bool
    {
        return $ticket->organizationId->equals($this->organizationId) && (
            TicketVisibilityFacts::FOR_EVERYONE === $ticket->visibility
            || (null !== $ticket->teamId && $this->isInTeam($ticket->teamId))
            || array_any($ticket->involvedUserIds(), fn (UserId $person): bool => $person->equals($this->viewerId))
            || $this->isAdmin
            || (null === $ticket->teamId && $this->isTriager())
        );
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
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
        return null !== $this->triagerId && $this->viewerId->equals($this->triagerId);
    }

    private function isInTeam(TeamId $teamId): bool
    {
        return array_any($this->teamIds, static fn (TeamId $ownTeamId): bool => $ownTeamId->equals($teamId));
    }
}
