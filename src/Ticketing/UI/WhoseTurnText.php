<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * The words and thresholds of the whose-turn view (FR-TKT-03, FR-TKT-18): each card's sentence, wait and meter.
 */
final class WhoseTurnText
{
    /** The wait that fills the meter. */
    public const int METER_FULL_DAYS = 60;
    /** A wait of more days than this is amber. */
    public const int BUSY_AFTER_DAYS = 10;
    /** A wait of more days than this is red, and so is the row's wait. */
    public const int TOO_LONG_AFTER_DAYS = 30;

    public const string INTRO = 'Each ticket sits with the one person who must act next. Longest waits first. Done tickets are left out.';
    public const string YOU = 'You';
    public const string OUTSIDE_THE_TEAM = ' · outside the team';
    /** The next page replaces this one; it names no count, as the last page may hold fewer cards and some are labels. */
    public const string NEXT_PAGE = 'Next page';
    public const string BACK_TO_THE_START = 'Back to the start';
    /** The list's NO_MATCHES names a person to pick; this view has no person filter. */
    public const string NO_MATCHES = 'No tickets match. Clear the search.';

    /**
     * Whole days from $since to $now; 0 for a wait under a day, or one that starts after $now.
     */
    public static function waitDaysOf(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 86400));
    }

    /**
     * "today", "since yesterday", "1 day" or "12 days". A wait under a day is "today" only when it began on the viewer's
     * calendar day, as Home says it, so a turn that came at 22:00 yesterday is not "today" at 09:00.
     */
    public static function waitOf(\DateTimeImmutable $since, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $waitDays = self::waitDaysOf($since, $now);

        return match (true) {
            $waitDays > 1 => $waitDays.' days',
            1 === $waitDays => '1 day',
            self::beganBeforeTheDayOf($since, $now, $viewerTimeZone) => 'since yesterday',
            default => 'today',
        };
    }

    /**
     * "6 tickets wait on Mia · the oldest for 12 days", "1 ticket waits on you · since today" (or "since yesterday", on
     * the viewer's calendar as waitOf), with OUTSIDE_THE_TEAM added for someone outside the team, and "6+ tickets
     * wait on …" when the search was capped.
     *
     * @param string $waitsOn             the person's first name, "you", or the label
     * @param bool   $isTicketCountCapped true when the search found more tickets than it counts, so the card may hold more
     */
    public static function sentenceOf(int $ticketCount, string $waitsOn, \DateTimeImmutable $oldestWaitingSince, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone, bool $isOutsideTheTeam, bool $isTicketCountCapped): string
    {
        $wait = match (true) {
            self::waitDaysOf($oldestWaitingSince, $now) > 0 => 'for '.self::waitOf($oldestWaitingSince, $now, $viewerTimeZone),
            self::beganBeforeTheDayOf($oldestWaitingSince, $now, $viewerTimeZone) => 'since yesterday',
            default => 'since today',
        };
        $isOneTicket = 1 === $ticketCount && !$isTicketCountCapped;

        return \sprintf(
            '%s %s on %s · %s%s',
            TicketListText::countOf($ticketCount, $isTicketCountCapped),
            $isOneTicket ? 'ticket waits' : 'tickets wait',
            $waitsOn,
            $isOneTicket ? $wait : 'the oldest '.$wait,
            $isOutsideTheTeam ? self::OUTSIDE_THE_TEAM : '',
        );
    }

    /**
     * The meter's colour: "too-much" above TOO_LONG_AFTER_DAYS, "busy" above BUSY_AFTER_DAYS, else "calm".
     */
    public static function meterLevelOf(int $oldestWaitDays): string
    {
        return match (true) {
            $oldestWaitDays > self::TOO_LONG_AFTER_DAYS => 'too-much',
            $oldestWaitDays > self::BUSY_AFTER_DAYS => 'busy',
            default => 'calm',
        };
    }

    public static function isTooLong(int $waitDays): bool
    {
        return $waitDays > self::TOO_LONG_AFTER_DAYS;
    }

    /**
     * "6 tickets · grouped by who acts next", or "1,000+ tickets · …" when a search found more than it counts.
     */
    public static function totalLineOf(int $ticketCount, bool $isTicketCountCapped = false): string
    {
        return \sprintf(
            '%s %s · grouped by who acts next',
            TicketListText::countOf($ticketCount, $isTicketCountCapped),
            1 === $ticketCount && !$isTicketCountCapped ? 'ticket' : 'tickets',
        );
    }

    private static function beganBeforeTheDayOf(\DateTimeImmutable $since, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): bool
    {
        return $since->setTimezone($viewerTimeZone)->format('Y-m-d') < $now->setTimezone($viewerTimeZone)->format('Y-m-d');
    }
}
