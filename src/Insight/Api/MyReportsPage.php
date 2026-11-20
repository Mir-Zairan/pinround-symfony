<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * One page of My reports.
 */
final readonly class MyReportsPage
{
    /**
     * @param list<MyReport>       $reports    at most HomeQueries::MY_REPORTS_PER_PAGE, newest first, then the highest id
     * @param MyReportsCursor|null $nextCursor after the page's last report when more follow; null on the last page
     */
    public function __construct(
        public array $reports,
        public ?MyReportsCursor $nextCursor,
    ) {
    }
}
