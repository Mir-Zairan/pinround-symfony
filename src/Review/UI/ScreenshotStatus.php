<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * Whether a screenshot's picture can be shown: pending while the processing worker checks and re-encodes it, ready
 * once it can, rejected when processing refused it.
 */
enum ScreenshotStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Rejected = 'rejected';
}
