<?php

declare(strict_types=1);

namespace App\Insight\Application\MyReports;

use App\Insight\Api\MyReport;

/**
 * A page of My reports sorted into its groups (ReportGroup::of), each keeping the page's order, newest first.
 */
final readonly class GroupedReports
{
    /**
     * @param array<value-of<ReportGroup>, list<MyReport>> $reportsByGroup
     */
    private function __construct(private array $reportsByGroup)
    {
    }

    /**
     * @param list<MyReport> $reports newest first
     */
    public static function of(array $reports, \DateTimeImmutable $now): self
    {
        $reportsByGroup = [];
        foreach (ReportGroup::cases() as $group) {
            $reportsByGroup[$group->value] = [];
        }
        foreach ($reports as $report) {
            $reportsByGroup[ReportGroup::of($report, $now)->value][] = $report;
        }

        return new self($reportsByGroup);
    }

    /**
     * @return list<MyReport>
     */
    public function reportsIn(ReportGroup $group): array
    {
        return $this->reportsByGroup[$group->value];
    }

    /**
     * @return list<MyReport> the first ReportGroup::shownCount() of the group, all of Needs you, none of Done
     */
    public function shownIn(ReportGroup $group): array
    {
        return \array_slice($this->reportsIn($group), 0, $group->shownCount());
    }

    /**
     * @return list<MyReport> the rest of the group, behind "Show N more" or "Show N done"
     */
    public function foldedIn(ReportGroup $group): array
    {
        $shownCount = $group->shownCount();

        return null === $shownCount ? [] : \array_slice($this->reportsIn($group), $shownCount);
    }
}
