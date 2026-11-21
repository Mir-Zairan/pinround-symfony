<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The client RegisterOAuthClient stored, with the metadata the registration answer repeats (RFC 7591, section 3.2.1).
 */
final readonly class RegisteredOAuthClient
{
    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     * @param list<string> $responseTypes
     */
    public function __construct(
        public string $clientId,
        public \DateTimeImmutable $issuedAt,
        public ?string $clientName,
        public array $redirectUris,
        public array $grantTypes,
        public array $responseTypes,
        public string $tokenEndpointAuthMethod,
    ) {
    }
}
