<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The command to email a reset link for this email, queued by /password/forgot for every request that passed the
 * throttle, whether or not a user has the email; SendPasswordResetLink decides in the worker.
 */
final readonly class EmailPasswordResetLink
{
    /**
     * @param string $email as typed, trimmed; not checked to be an address
     */
    public function __construct(public string $email)
    {
    }
}
