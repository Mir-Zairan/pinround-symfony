<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

use App\Insight\Api\MyReportsCursor;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\ListFilter;

/**
 * My reports' next-page MyReportsCursor as the "Show more" link carries it, in AFTER_QUERY: "<reported at in Unix
 * seconds>.<ticket id>". Tickets keep their creation time in whole seconds, so the seconds name the exact time. It is
 * not signed: it holds only what the viewer already saw, and a forged one only moves where the page starts.
 */
final class MyReportsCursorParameter
{
    public const string AFTER_QUERY = 'after';

    private const string AFTER = '/\A(\d{1,11})\.(.+)\z/s';

    public static function valueOf(MyReportsCursor $cursor): string
    {
        return $cursor->reportedAt->getTimestamp().'.'.$cursor->ticketId->toString();
    }

    /**
     * @return MyReportsCursor|null null when the value is not one valueOf makes
     */
    public static function cursorOf(string $after): ?MyReportsCursor
    {
        if (1 !== preg_match(self::AFTER, $after, $parts) || !ListFilter::isCanonicalUuid($parts[2])) {
            return null;
        }
        try {
            return new MyReportsCursor(new \DateTimeImmutable('@'.$parts[1]), new TicketId($parts[2]));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
