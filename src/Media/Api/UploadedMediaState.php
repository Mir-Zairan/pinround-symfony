<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * Where an uploaded picture stands in processing, as the person who uploaded it may see it.
 */
enum UploadedMediaState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Rejected = 'rejected';
}
