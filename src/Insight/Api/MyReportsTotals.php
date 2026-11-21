<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * How many reports My reports holds on all its pages, for its intro line.
 */
final readonly class MyReportsTotals
{
    /**
     * @param int $reportCount   every ticket the viewer reported and may see, open and done
     * @param int $needsYouCount the open ones of them with a question that waits on the viewer's answer
     *                           (ReportGroup::NeedsYou)
     */
    public function __construct(
        public int $reportCount,
        public int $needsYouCount,
    ) {
    }
}
