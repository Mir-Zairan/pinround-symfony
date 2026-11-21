<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * An authorize request AuthorizeOAuthClient checked: what the consent page shows and posts back.
 */
final readonly class OAuthAuthorizationRequest
{
    /**
     * @param string|null  $clientName   what the client calls itself; null when it gave no name
     * @param string       $redirectUri  exactly as the request carried it; it matches one the client registered
     * @param string       $redirectHost the redirect URI's host in lowercase, without port, for the person to see
     *                                   where the code goes
     * @param string|null  $state        null when the request had none
     * @param list<string> $scopeNames   the scopes asked for, at least one, in ApiTokenScope's order
     */
    public function __construct(
        public string $clientId,
        public ?string $clientName,
        public string $redirectUri,
        public string $redirectHost,
        public string $codeChallenge,
        public ?string $state,
        public array $scopeNames,
    ) {
    }

    /**
     * The scope parameter as the consent form posts it back: the names joined by single spaces.
     */
    public function scope(): string
    {
        return implode(' ', $this->scopeNames);
    }
}
