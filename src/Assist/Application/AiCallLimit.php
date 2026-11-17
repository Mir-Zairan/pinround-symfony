<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Organization\Api\ActorContext;

/**
 * pinround's own limit of server-side AI calls per person in an organization (ADR-0018 › Metering, ai-architecture.md ›
 * Cost, rate limits and metering): 30 an hour, keyed by the organization and the user, so a person's calls in one
 * organization never use up another's. Consumed before any provider is asked.
 */
interface AiCallLimit
{
    /**
     * Counts one call of the person in the caller's organization. A refused call is not counted.
     *
     * @throws AiRateLimited with no provider when the person already made 30 calls in that organization in the current
     *                       hour's window
     * @throws \Throwable    the counter's storage error when it cannot be read or written; the call is not allowed
     */
    public function consume(ActorContext $caller): void;

    /**
     * Whether the person may make one more call in the caller's organization now, without counting one, so a page
     * that queues a call can refuse it up front. consume() still decides: a call made meanwhile may use the last one.
     *
     * @throws \Throwable the counter's storage error when it cannot be read; the call is not allowed
     */
    public function hasCallsLeft(ActorContext $caller): bool;
}
