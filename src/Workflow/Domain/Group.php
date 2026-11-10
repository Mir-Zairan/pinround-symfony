<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * One of the five fixed groups every step belongs to (ADR-0014). Managers compare teams by these groups.
 */
enum Group: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Check = 'check';
    case Wait = 'wait';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::Doing => 'Doing',
            self::Check => 'Checking',
            self::Wait => 'Waiting',
            self::Done => 'Done',
        };
    }

    /**
     * The one-line hint under a group header in lists; null for Done, which has none.
     */
    public function listHint(): ?string
    {
        return match ($this) {
            self::Wait => 'Someone must answer first',
            self::Check => 'Being checked',
            self::Doing => 'Being built',
            self::Todo => 'Not started',
            self::Done => null,
        };
    }

    /**
     * The groups in the order lists show them: what blocks others comes first.
     *
     * @return list<self>
     */
    public static function inListOrder(): array
    {
        return [self::Wait, self::Check, self::Doing, self::Todo, self::Done];
    }

    /**
     * Zero for the group shown first.
     */
    public function listPosition(): int
    {
        return (int) array_search($this, self::inListOrder(), true);
    }
}
