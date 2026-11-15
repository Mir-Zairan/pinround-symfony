<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Where an AI pin stands; a person's pin has none.
 */
enum AiPinState: string
{
    case Suggested = 'suggested';
    case Kept = 'kept';
    case Dismissed = 'dismissed';
}
