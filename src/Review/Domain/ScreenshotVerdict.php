<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The owner's verdict on a screenshot of their draft round: "Looks right" or "Not right yet".
 */
enum ScreenshotVerdict: string
{
    case LooksRight = 'looks_right';
    case NotRight = 'not_right';
}
