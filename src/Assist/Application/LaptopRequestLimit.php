<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Organization\Api\ActorContext;

/**
 * How many laptop AI requests (POST /ai/laptop/package and /ai/laptop/result together) a person may send in an
 * organization: 30 in each minute of the clock, keyed by the organization and the user. A refused result is written to
 * the audit log, so without this limit a loop of made-up package ids would fill it. Counted before the body is read.
 */
interface LaptopRequestLimit
{
    public const int REQUESTS_PER_MINUTE = 30;

    /**
     * Counts one request of the person in the caller's organization. A refused request is not counted.
     *
     * @throws LaptopRequestsLimited when the person already sent REQUESTS_PER_MINUTE in the current minute
     * @throws \Throwable            the counter's storage error when it cannot be read or written; the request is not
     *                               allowed
     */
    public function consume(ActorContext $caller): void;
}
