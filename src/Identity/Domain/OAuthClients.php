<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface OAuthClients
{
    /**
     * Saves the client at once, in the caller's transaction when there is one.
     */
    public function add(OAuthClient $client): void;

    /**
     * @return OAuthClient|null null when no client has exactly this client_id (compared case-sensitively)
     */
    public function byClientId(string $clientId): ?OAuthClient;
}
