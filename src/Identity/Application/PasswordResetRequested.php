<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Someone asked for a reset link for this email on /password/forgot. Queued for every request that passed the
 * throttle, whether or not a user has the email; SendPasswordResetLink decides in the worker.
 */
final readonly class PasswordResetRequested
{
    /**
     * @param string $email as typed, trimmed; not checked to be an address
     */
    public function __construct(public string $email)
    {
    }
}
