<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;

/**
 * The words of the tickets list (tickets-list-and-whose-turn.md, List view) that depend on the rows, and the date the
 * ticket page shares with it.
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
     * RelativeTime::sinceOf: "now", "5 min ago", "3 h ago", then "2 Nov".
     */
    public static function updatedOf(\DateTimeImmutable $updatedAt, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        return RelativeTime::sinceOf($updatedAt, $now, $viewerTimeZone);
    }

    /**
     * RelativeTime::dateOf: "2 Nov", or "2 Nov 2025" in another year. The ticket page prints its created date with it too.
     */
    public static function dateOf(\DateTimeImmutable $at, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        return RelativeTime::dateOf($at, $now, $viewerTimeZone);
    }
}
