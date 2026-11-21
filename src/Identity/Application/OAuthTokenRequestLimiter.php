<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface OAuthTokenRequestLimiter
{
    /**
     * Counts one token or revocation request from this client IP, whether or not it then issues or revokes anything.
     *
     * @throws TooManyOAuthTokenRequests when this client IP asked too often
     */
    public function consume(string $clientIp): void;
}
