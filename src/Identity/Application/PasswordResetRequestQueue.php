<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PasswordResetRequestQueue
{
    /**
     * Hands the request to a worker; returns once it is queued, before it is handled.
     */
    public function add(EmailPasswordResetLink $request): void;
}
