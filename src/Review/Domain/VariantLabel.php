<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Which side of a compare a screenshot of a review round shows.
 */
enum VariantLabel: string
{
    case Before = 'before';
    case After = 'after';
}
