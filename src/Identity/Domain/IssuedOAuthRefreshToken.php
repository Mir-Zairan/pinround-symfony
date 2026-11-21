<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A refresh token just issued, with the plain token that goes once to the client. It can be neither serialized nor
 * dumped, so the plain token never reaches a queue, a session or a log by accident.
 */
final readonly class IssuedOAuthRefreshToken
{
    public function __construct(private OAuthRefreshToken $token, #[\SensitiveParameter] private string $plainToken)
    {
    }

    public function token(): OAuthRefreshToken
    {
        return $this->token;
    }

    /**
     * The plain token, for the one token response that hands it to the client.
     */
    public function reveal(): string
    {
        return $this->plainToken;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token->id(), 'plainToken' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An issued OAuth refresh token is never serialized.');
    }
}
