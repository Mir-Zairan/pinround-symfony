<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

/**
 * The notification kinds the bell shows (realtime-and-notifications.md, "Notification kinds"); your_turn and check
 * are left to Home.
 */
enum BellKind: string
{
    case Ping = 'ping';
    case Question = 'question';
    case Answer = 'answer';
    case Reply = 'reply';
    case Assigned = 'assigned';
    case RoundShared = 'round_shared';
    case ClosedDuplicate = 'closed_duplicate';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
