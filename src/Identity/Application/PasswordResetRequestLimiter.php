<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PasswordResetRequestLimiter
{
    /**
     * Counts one request for a reset link from this client for this email, whether or not a user has the email.
     *
     * @throws TooManyPasswordResetRequests when this email and client, or this client alone, asked too often
     */
    public function consume(string $email, string $clientIp): void;
}
