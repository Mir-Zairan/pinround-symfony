<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\SharedKernel\Domain\UserId;

/**
 * pinround's own limit of server-side AI calls per person (ai-architecture.md › Cost, rate limits and metering): 30 an
 * hour, counted across the person's organizations. Consumed before any provider is asked.
 */
interface AiCallLimit
{
    /**
     * Counts one call of the person. A refused call is not counted.
     *
     * @throws AiRateLimited with no provider when the person already made 30 calls in the current hour's window
     */
    public function consume(UserId $userId): void;
}
