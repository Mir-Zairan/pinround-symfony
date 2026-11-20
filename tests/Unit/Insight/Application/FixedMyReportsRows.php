<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application;

use App\Insight\Api\MyReport;
use App\Insight\Api\MyReportsCursor;
use App\Insight\Api\TicketViewer;
use App\Insight\Application\MyReports\MyReportsRows;

/**
 * Like the database: orders the reports it was given newest first, then by the highest id, keeps only those after the
 * cursor, and at most the limit; it records each call's cursor and limit.
 */
final class FixedMyReportsRows implements MyReportsRows
{
    /** @var list<array{?MyReportsCursor, int}> */
    public array $calls = [];

    /**
     * @param list<MyReport> $reports
     */
    public function __construct(private readonly array $reports = [])
    {
    }

    public function reportsOf(TicketViewer $viewer, ?MyReportsCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('My reports are read at least one at a time.');
        }
        $this->calls[] = [$after, $limit];
        $reports = $this->reports;
        usort($reports, static fn (MyReport $one, MyReport $other): int => [$other->reportedAt, strcmp($other->ticketId->toString(), $one->ticketId->toString())] <=> [$one->reportedAt, 0]);
        if (null !== $after) {
            $reports = array_values(array_filter($reports, static fn (MyReport $report): bool => [$report->reportedAt, strcmp($report->ticketId->toString(), $after->ticketId->toString())] < [$after->reportedAt, 0]));
        }

        return \array_slice($reports, 0, $limit);
    }
}
