<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A token just issued, with the plain token the user sees once. It can be neither serialized nor dumped, so the plain
 * token never reaches a queue, a session or a log by accident.
 */
final readonly class IssuedApiToken
{
    public function __construct(private ApiToken $token, #[\SensitiveParameter] private string $plainToken)
    {
    }

    public function token(): ApiToken
    {
        return $this->token;
    }

    /**
     * The plain token, for the one page that shows it after creation only.
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
        return ['token' => $this->token->prefix().'…', 'plainToken' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An issued API token is never serialized.');
    }
}
