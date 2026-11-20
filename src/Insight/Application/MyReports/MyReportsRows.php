<?php

declare(strict_types=1);

namespace App\Insight\Application\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\MyReportsCursor;
use App\Insight\Api\TicketViewer;

/**
 * The viewer's reports as the database returns them, for HomeQueriesService, which cuts the page and makes its cursor.
 */
interface MyReportsRows
{
    /**
     * One query; only tickets the viewer reported and may see.
     *
     * @param MyReportsCursor|null $after null from the newest; else only the reports after it
     * @param int                  $limit at most this many reports, at least 1
     *
     * @return list<MyReport> newest first, then the highest ticket id
     *
     * @throws \InvalidArgumentException when the limit is below 1, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function reportsOf(TicketViewer $viewer, ?MyReportsCursor $after, int $limit): array;
}
