<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

/**
 * What a pick in the person picker does, which sets its title, sub line, the count beside each person and the order
 * (prototype "Person picker").
 */
enum PersonPickerMode: string
{
    /** The tickets list shows the picked person's tickets. */
    case Filter = 'filter';

    /** The ticket is given to the picked person (P03.T8). */
    case Assign = 'assign';

    /**
     * @param string $ticketKey the key of the ticket an Assign pick gives, as "SHOP-12"; Filter ignores it
     */
    public function title(string $ticketKey): string
    {
        return match ($this) {
            self::Filter => 'Show tickets of…',
            self::Assign => 'Give '.$ticketKey.' to…',
        };
    }

    public function sub(): string
    {
        return match ($this) {
            self::Filter => 'Sorted by open tickets assigned to them.',
            self::Assign => 'The ticket moves to their list. Sorted by how much is already waiting on them.',
        };
    }

    /**
     * The text beside a person: Filter counts the open tickets assigned to them, Assign the open tickets waiting on them.
     */
    public function sideTextOf(int $count): string
    {
        return match ($this) {
            self::Filter => $count.' open',
            self::Assign => $count.' waiting',
        };
    }
}
