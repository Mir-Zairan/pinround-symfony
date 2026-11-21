<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

enum ButtonVariant: string
{
    case Filled = 'filled';
    case Tonal = 'tonal';
    case Outline = 'outline';
    case Text = 'text';
    /** A text button in the error colour, for an action that removes or deletes something. */
    case Danger = 'danger';
}
