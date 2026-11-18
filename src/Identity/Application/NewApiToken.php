<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * A token just made on the tokens page, with the plain token the person sees once. Like IssuedApiToken it can be
 * neither serialized nor dumped, so the plain token never reaches a session, a queue or a log by accident.
 */
final readonly class NewApiToken
{
    public function __construct(public ApiTokenView $token, #[\SensitiveParameter] private string $plainToken)
    {
    }

    /**
     * The plain token, for the one response that shows it after creation only.
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
        return ['token' => $this->token->prefix.'…', 'plainToken' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A new API token is never serialized.');
    }
}
