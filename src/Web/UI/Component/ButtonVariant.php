<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

enum ButtonVariant: string
{
    case Filled = 'filled';
    case Tonal = 'tonal';
    case Outline = 'outline';
    case Text = 'text';
}
