<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * Where a next page of My reports starts: right after the report made at this time with this ticket id, in
 * HomeQueries::myReports's order (newest first, then the highest id). A forged one only moves where the page starts;
 * the page still holds only the viewer's own reports that they may see.
 */
final readonly class MyReportsCursor
{
    public function __construct(
        public \DateTimeImmutable $reportedAt,
        public TicketId $ticketId,
    ) {
    }

    public static function after(MyReport $report): self
    {
        return new self($report->reportedAt, $report->ticketId);
    }
}
