<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;

/**
 * Each viewer's predicate with the teams they are given here, as the real one reads their team roles; nobody is admin
 * and the organization has no triager. A viewer not listed is in no team.
 */
final class MemberTicketVisibilities implements TicketVisibilities
{
    /** @var list<string> the viewer of each predicate read */
    public array $reads = [];

    /**
     * @param array<string, list<TeamId>> $teamIdsByViewer by viewer id
     */
    public function __construct(private readonly array $teamIdsByViewer = [])
    {
    }

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        $this->reads[] = $viewerId->toString();

        return TicketVisibility::for($organizationId, $viewerId, $this->teamIdsByViewer[$viewerId->toString()] ?? [], false, null);
    }
}
