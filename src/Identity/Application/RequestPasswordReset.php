<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * "Forgot password" (FR-ID-08), the part the web request runs: after the throttle it queues the request without
 * looking the email up, so a known, unknown, blocked or password-less email costs the same work and time.
 * SendPasswordResetLink does the rest in the worker.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        private PasswordResetRequestLimiter $limiter,
        private PasswordResetRequestQueue $queue,
    ) {
    }

    /**
     * @throws TooManyPasswordResetRequests before anything is queued
     */
    public function __invoke(string $email, string $clientIp): void
    {
        $this->limiter->consume($email, $clientIp);
        $this->queue->add(new PasswordResetRequested(trim($email)));
    }
}
