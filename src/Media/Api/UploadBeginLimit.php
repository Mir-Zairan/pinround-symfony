<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\UserId;

/**
 * How many upload begins a person may make, on the web (POST /media/intents) and through MCP create_upload together:
 * media_upload_begin_per_user of rate_limiter.yaml, keyed by the user id. Each caller counts a begin before it asks
 * Uploads::begin, so one refused afterwards counts too, and one person cannot script begins against the storage.
 */
interface UploadBeginLimit
{
    /**
     * Counts one begin of $userId, whether the limit accepts it or not.
     *
     * @throws UploadRefused when $userId used up the begins of the current window: UploadRefused::ADDING_TOO_FAST,
     *                       with the seconds until the window ends as retryAfterSeconds
     * @throws \Throwable    the counter's storage error when it cannot be read or written; the begin is not allowed
     */
    public function consume(UserId $userId): void;
}
