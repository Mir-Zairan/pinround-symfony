<?php

declare(strict_types=1);

namespace App\Web\UI\Team;

/**
 * How heavy a person's load looks on the Team page's bar (prototype "Team: people and workload"): amber from
 * BUSY_FROM open tickets waiting on them, red from TOO_MUCH_FROM. The value is the bar's CSS modifier.
 */
enum WorkloadLevel: string
{
    case Normal = 'normal';
    case Busy = 'busy';
    case TooMuch = 'too-much';

    public const int BUSY_FROM = 8;
    public const int TOO_MUCH_FROM = 20;

    public static function of(int $waitingTickets): self
    {
        return match (true) {
            $waitingTickets >= self::TOO_MUCH_FROM => self::TooMuch,
            $waitingTickets >= self::BUSY_FROM => self::Busy,
            default => self::Normal,
        };
    }
}
