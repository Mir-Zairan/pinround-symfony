<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

/**
 * How long ago something happened, in the words the ticket page's What happened, the tickets list and the thread share.
 * It is here because Ticketing's and Conversation's UI both print it and may not see each other.
 */
final class RelativeTime
{
    /**
     * "now" within a minute (or for a time ahead of $now), then "5 min ago", "3 h ago", and from a day on the date in
     * the viewer's time zone: "2 Nov", with the year when it is not $now's in that zone.
     */
    public static function sinceOf(\DateTimeImmutable $at, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $seconds = $now->getTimestamp() - $at->getTimestamp();
        if ($seconds < 60) {
            return 'now';
        }
        if ($seconds < 3600) {
            return \sprintf('%d min ago', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('%d h ago', intdiv($seconds, 3600));
        }

        return self::dateOf($at, $now, $viewerTimeZone);
    }

    /**
     * The date in the viewer's time zone, however recent: "2 Nov", with the year when it is not $now's in that zone
     * ("2 Nov 2025").
     */
    public static function dateOf(\DateTimeImmutable $at, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $atInZone = $at->setTimezone($viewerTimeZone);

        return $atInZone->format($atInZone->format('Y') === $now->setTimezone($viewerTimeZone)->format('Y') ? 'j M' : 'j M Y');
    }
}
