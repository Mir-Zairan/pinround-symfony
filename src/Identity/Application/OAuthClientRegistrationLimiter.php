<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface OAuthClientRegistrationLimiter
{
    /**
     * Counts one client registration request from this client IP, whether or not it then registers a client.
     *
     * @throws TooManyOAuthClientRegistrations when this client IP asked too often
     */
    public function consume(string $clientIp): void;
}
