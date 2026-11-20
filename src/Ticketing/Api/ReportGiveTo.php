<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * "Give it to" of one team, as a reporter in that team sees it (report-a-problem.md, Where it goes): "The team (<lead>
 * decides)", or one of up to four people who are not testers, fewest open tickets first. Report a problem and a report
 * draft's Send both offer this, and both give a report only to a person it offers.
 */
final readonly class ReportGiveTo
{
    public const string NOT_OFFERED = 'Pick someone from the list, or the team.';

    /**
     * @param string|null                            $leadFirstName for "The team (Lena decides)"; null for a team with no lead
     * @param list<array{id: string, label: string}> $people        least loaded first; the reporter's label is "<first> (you)"
     */
    public function __construct(
        public ?string $leadFirstName,
        public array $people,
    ) {
    }

    public function offers(string $userId): bool
    {
        return \in_array($userId, array_column($this->people, 'id'), true);
    }
}
