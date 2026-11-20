<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\Api\TeamProjectChoice;

/**
 * One team chip of Report a problem, with what picking it offers (report-a-problem.md, Where it goes).
 */
final readonly class ReportTeamChoice
{
    /**
     * @param ReportGiveTo|null                 $giveTo   null when the reporter has no role in the team, so "Give it to"
     *                                                    does not show
     * @param non-empty-list<TeamProjectChoice> $projects the team's projects, the first is the default
     */
    public function __construct(
        public TeamId $id,
        public string $name,
        public ?ReportGiveTo $giveTo,
        public array $projects,
    ) {
    }

    public function isOwn(): bool
    {
        return null !== $this->giveTo;
    }
}
