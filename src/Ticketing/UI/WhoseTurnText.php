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

    /**
     * Whole days from $since to $now; 0 for a wait under a day, or one that starts after $now.
     */
    public static function waitDaysOf(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 86400));
    }

    /**
     * "today", "1 day" or "12 days".
     */
    public static function waitOf(int $waitDays): string
    {
        return match ($waitDays) {
            0 => 'today',
            1 => '1 day',
            default => $waitDays.' days',
        };
    }

    /**
     * "6 tickets wait on Mia · the oldest for 12 days", "1 ticket waits on you · since today", with
     * OUTSIDE_THE_TEAM added for someone outside the team.
     *
     * @param string $waitsOn the person's first name, "you", or the label
     */
    public static function sentenceOf(int $ticketCount, string $waitsOn, int $oldestWaitDays, bool $isOutsideTheTeam): string
    {
        $wait = 0 === $oldestWaitDays ? 'since today' : 'for '.self::waitOf($oldestWaitDays);

        return \sprintf(
            '%s %s on %s · %s%s',
            TicketListText::countOf($ticketCount, false),
            1 === $ticketCount ? 'ticket waits' : 'tickets wait',
            $waitsOn,
            1 === $ticketCount ? $wait : 'the oldest '.$wait,
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
     * "6 tickets · grouped by who acts next".
     */
    public static function totalLineOf(int $ticketCount): string
    {
        return \sprintf('%s %s · grouped by who acts next', TicketListText::countOf($ticketCount, false), 1 === $ticketCount ? 'ticket' : 'tickets');
    }
}
