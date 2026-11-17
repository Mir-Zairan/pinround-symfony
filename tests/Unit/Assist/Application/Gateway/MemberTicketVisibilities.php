<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;

/**
 * Every viewer as a plain member of the organization asked about: no team, not admin, no triager. ListedIntakeCandidates
 * decides who sees a ticket by its own list, so only the viewer and the organization of the predicate matter.
 */
final class MemberTicketVisibilities implements TicketVisibilities
{
    /** @var list<string> the viewer of each predicate read */
    public array $reads = [];

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        $this->reads[] = $viewerId->toString();

        return TicketVisibility::for($organizationId, $viewerId, [], false, null);
    }
}
