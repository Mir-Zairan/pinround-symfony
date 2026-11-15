<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * Where a picture is in processing: pending until the worker has checked and re-encoded it, then ready or rejected for
 * good.
 */
enum MediaImageStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Rejected = 'rejected';
}
