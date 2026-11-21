<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Demo;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Who plays each part in the showcase tickets of SeedShowcaseTickets, and where they work.
 */
final readonly class ShowcaseCast
{
    /**
     * @param UserId $lena      the Shop team's lead, who reviews the code
     * @param UserId $omar      the developer each ticket is given to
     * @param UserId $mia       the tester, who reports each ticket
     * @param string $projectId the SHOP project's uuid
     */
    public function __construct(
        public OrganizationId $organizationId,
        public UserId $lena,
        public UserId $omar,
        public UserId $mia,
        public string $projectId,
    ) {
    }
}
