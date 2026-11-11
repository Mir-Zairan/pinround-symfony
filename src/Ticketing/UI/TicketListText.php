<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;

/**
 * The words of the tickets list (tickets-list-and-whose-turn.md, List view) that depend on the rows.
 */
final class TicketListText
{
    /** Shown bold in the error colour. */
    public const array URGENT_PRIORITIES = ['blocker', 'critical'];

    /**
     * "41", "1,000" or, for a step that holds more than it counts, "1,000+".
     */
    public static function countOf(int $ticketCount, bool $isCountCapped): string
    {
        return number_format($ticketCount).($isCountCapped ? '+' : '');
    }

    /**
     * The line under the heading: "41 tickets · grouped by step", the sum of the shown steps' counts, with "+" when
     * any step's count is capped.
     *
     * @param list<TicketListGroup> $groups
     */
    public static function totalLineOf(array $groups): string
    {
        $ticketCount = 0;
        $isCountCapped = false;
        foreach ($groups as $group) {
            foreach ($group->steps as $step) {
                $ticketCount += $step->ticketCount;
                $isCountCapped = $isCountCapped || $step->isCountCapped;
            }
        }

        return \sprintf('%s %s · grouped by step', self::countOf($ticketCount, $isCountCapped), 1 === $ticketCount && !$isCountCapped ? 'ticket' : 'tickets');
    }

    public static function stepCountOf(TicketListStep $step): string
    {
        return self::countOf($step->ticketCount, $step->isCountCapped);
    }

    /**
     * "Bug · Release 2.4 · Turn: you": the type, the release when there is one, and whose turn it is by first name,
     * "you" for the viewer, unless the ticket is done or no named person has the turn.
     */
    public static function metaOf(TicketListRow $row, UserId $viewerId, bool $isDone): string
    {
        $parts = [ucfirst($row->type)];
        if (null !== $row->release && '' !== $row->release) {
            $parts[] = 'Release '.$row->release;
        }
        if (!$isDone && null !== $row->turnUserId) {
            if ($row->turnUserId->equals($viewerId)) {
                $parts[] = 'Turn: you';
            } elseif (null !== $row->turnUserFirstName && '' !== $row->turnUserFirstName) {
                $parts[] = 'Turn: '.$row->turnUserFirstName;
            }
        }

        return implode(' · ', $parts);
    }

    public static function priorityOf(TicketListRow $row): string
    {
        return ucfirst($row->priority);
    }

    public static function isUrgent(TicketListRow $row): bool
    {
        return \in_array($row->priority, self::URGENT_PRIORITIES, true);
    }

    /**
     * "now" within a minute (or for a time ahead of $now), then "5 min ago", "3 h ago", and from a day on the date in
     * the viewer's time zone: "2 Nov", with the year when it is not $now's in that zone.
     */
    public static function updatedOf(\DateTimeImmutable $updatedAt, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $seconds = $now->getTimestamp() - $updatedAt->getTimestamp();
        if ($seconds < 60) {
            return 'now';
        }
        if ($seconds < 3600) {
            return \sprintf('%d min ago', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('%d h ago', intdiv($seconds, 3600));
        }
        $updatedInZone = $updatedAt->setTimezone($viewerTimeZone);

        return $updatedInZone->format($updatedInZone->format('Y') === $now->setTimezone($viewerTimeZone)->format('Y') ? 'j M' : 'j M Y');
    }
}
