<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * A viewer's ticket visibility as SharedKernel data, for an Insight read that shows only the tickets the viewer may see;
 * an Insight Api may not name Ticketing's TicketVisibility. Build it from the viewer's TicketVisibility, field by field
 * (organizationId(), viewerId(), teamIds(), isAdmin(), isTriager()), so the rule stays Ticketing's.
 */
final readonly class TicketViewer
{
    /**
     * @param list<TeamId> $teamIds   the teams the viewer has any team role in
     * @param bool         $isAdmin   whether the viewer has the org role Admin
     * @param bool         $isTriager whether the viewer is the organization's triager
     */
    public function __construct(
        public OrganizationId $organizationId,
        public UserId $viewerId,
        public array $teamIds,
        public bool $isAdmin,
        public bool $isTriager,
    ) {
    }
}
