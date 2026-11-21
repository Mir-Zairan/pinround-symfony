<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A code just issued, with the plain code that goes once to the client's redirect URI. It can be neither serialized
 * nor dumped, so the plain code never reaches a queue, a session or a log by accident.
 */
final readonly class IssuedOAuthAuthorizationCode
{
    public function __construct(private OAuthAuthorizationCode $code, #[\SensitiveParameter] private string $plainCode)
    {
    }

    public function code(): OAuthAuthorizationCode
    {
        return $this->code;
    }

    /**
     * The plain code, for the one redirect that hands it to the client.
     */
    public function reveal(): string
    {
        return $this->plainCode;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['code' => $this->code->id(), 'plainCode' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An issued OAuth authorization code is never serialized.');
    }
}
