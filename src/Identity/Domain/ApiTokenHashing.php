<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Hashes a personal access token with the server-side pepper (ADR-0021), so a leaked idn_api_token table alone cannot
 * be used to check guessed tokens. The same token always gives the same hash, which resolve_api_token looks up.
 */
interface ApiTokenHashing
{
    /**
     * @return string the 32 raw bytes of the hash, for the bytea column
     */
    public function hash(#[\SensitiveParameter] string $plainToken): string;
}
