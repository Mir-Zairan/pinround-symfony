<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\TeamProjectChoice;

/**
 * One team chip of Report a problem, with what picking it offers (report-a-problem.md, Where it goes).
 */
final readonly class ReportTeamChoice
{
    /**
     * @param bool                                   $isOwn         the reporter has a role in it, so "Give it to" shows
     * @param string|null                            $leadFirstName for "The team (Lena decides)"; null when not the reporter's team
     * @param list<array{id: string, label: string}> $giveTo        up to four non-tester people, least loaded first; the
     *                                                              reporter's label is "<first> (you)"
     * @param non-empty-list<TeamProjectChoice>      $projects      the team's projects, the first is the default
     */
    public function __construct(
        public TeamId $id,
        public string $name,
        public bool $isOwn,
        public ?string $leadFirstName,
        public array $giveTo,
        public array $projects,
    ) {
    }
}
