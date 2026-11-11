<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * How urgent a ticket is. Lists sort by rank, kept next to the priority in tkt_ticket.priority_rank so the list
 * indexes can order by it.
 */
enum Priority: string
{
    case Blocker = 'blocker';
    case Critical = 'critical';
    case Major = 'major';
    case Normal = 'normal';
    case Minor = 'minor';

    /**
     * 0 for the most urgent (blocker) to 4 for the least (minor); the tkt_ticket_priority_rank_check keeps the same map.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Blocker => 0,
            self::Critical => 1,
            self::Major => 2,
            self::Normal => 3,
            self::Minor => 4,
        };
    }
}
