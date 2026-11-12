<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

/**
 * What a pick in the person picker does, which sets its title and sub line (prototype "Person picker"). Assigning a
 * ticket (P03.T8) adds its own case.
 */
enum PersonPickerMode: string
{
    /** The tickets list shows the picked person's tickets. */
    case Filter = 'filter';

    public function title(): string
    {
        return match ($this) {
            self::Filter => 'Show tickets of…',
        };
    }

    public function sub(): string
    {
        return match ($this) {
            self::Filter => 'Sorted by open tickets assigned to them.',
        };
    }
}
