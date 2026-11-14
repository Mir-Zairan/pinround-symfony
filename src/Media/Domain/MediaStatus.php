<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Where a media object is in processing: pending until the worker has checked and re-encoded the upload, then ready or
 * rejected for good.
 */
enum MediaStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Rejected = 'rejected';
}
