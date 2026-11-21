<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * What the token endpoint hands the client once (RFC 6749 section 5.1): a plain access token, and a plain refresh token
 * when the client registered the refresh_token grant. It can be neither serialized nor dumped, so neither token ever
 * reaches a queue, a session or a log by accident.
 */
final readonly class IssuedOAuthTokens
{
    /**
     * @param list<string> $scopeNames ApiTokenScope values the access token carries
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $accessToken,
        #[\SensitiveParameter]
        private ?string $refreshToken,
        public int $expiresInSeconds,
        public array $scopeNames,
    ) {
    }

    public function revealAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @return string|null null when the client did not register the refresh_token grant
     */
    public function revealRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'refreshToken' => '[redacted]', 'expiresInSeconds' => $this->expiresInSeconds, 'scopeNames' => $this->scopeNames];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Issued OAuth tokens are never serialized.');
    }
}
