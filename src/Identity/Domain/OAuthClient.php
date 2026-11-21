<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * An OAuth client that registered itself (RFC 7591, ADR-0050): the extension, Claude Code or a Claude connector. Only
 * public clients: no secret, so every grant needs PKCE with S256. Global, like the user: a client registers before
 * anyone logs in and serves people of any organization; the organization is picked per grant on the consent page.
 */
final class OAuthClient
{
    public const string TOKEN_ENDPOINT_AUTH_METHOD = 'none';
    public const string RESPONSE_TYPE = 'code';
    public const string CODE_CHALLENGE_METHOD = 'S256';
    public const int CLIENT_NAME_MAX_LENGTH = 100;
    public const int MAX_REDIRECT_URIS = 5;
    private const int CLIENT_ID_RANDOM_BYTES = 24;

    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     */
    private function __construct(
        private readonly string $clientId,
        private readonly ?string $clientName,
        private readonly array $redirectUris,
        private readonly array $grantTypes,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A new client with a random client_id: 24 random bytes in base64url, 32 characters.
     *
     * @param string|null          $clientName   what the client calls itself; null for none, else Unicode space trimmed,
     *                                           1 to CLIENT_NAME_MAX_LENGTH characters, one line
     * @param list<string>         $redirectUris 1 to MAX_REDIRECT_URIS, each by the rule of OAuthRedirectUri; a repeat
     *                                           counts once and the first order is kept
     * @param list<OAuthGrantType> $grantTypes   authorization_code among them; repeats count once
     * @param \DateTimeImmutable   $registeredAt kept to the whole second, in UTC
     *
     * @throws InvalidOAuthRedirectUri    when the list is empty or too long, or a URI breaks the rule
     * @throws InvalidOAuthClientMetadata when the name is blank, too long or not one line of plain text, or
     *                                    authorization_code is not among the grant types
     */
    public static function register(?string $clientName, array $redirectUris, array $grantTypes, \DateTimeImmutable $registeredAt): self
    {
        $uniqueRedirectUris = array_values(array_unique($redirectUris));
        if ([] === $uniqueRedirectUris) {
            throw new InvalidOAuthRedirectUri('Give at least one redirect URI.');
        }
        if (\count($uniqueRedirectUris) > self::MAX_REDIRECT_URIS) {
            throw new InvalidOAuthRedirectUri(\sprintf('A client has at most %d redirect URIs.', self::MAX_REDIRECT_URIS));
        }
        $checkedRedirectUris = array_map(static fn (string $uri): string => new OAuthRedirectUri($uri)->toString(), $uniqueRedirectUris);
        if (!\in_array(OAuthGrantType::AuthorizationCode, $grantTypes, true)) {
            throw new InvalidOAuthClientMetadata('A client uses the authorization_code grant.');
        }
        // In the order of the cases, so the same choice is always stored the same way.
        $grantTypeValues = array_values(array_map(
            static fn (OAuthGrantType $grantType): string => $grantType->value,
            array_filter(OAuthGrantType::cases(), static fn (OAuthGrantType $grantType): bool => \in_array($grantType, $grantTypes, true)),
        ));

        return new self(
            RandomToken::base64Url(self::CLIENT_ID_RANDOM_BYTES),
            null === $clientName ? null : self::checkedClientName($clientName),
            $checkedRedirectUris,
            $grantTypeValues,
            // idn_oauth_client.created_at keeps whole seconds; PostgreSQL would round a fraction up.
            new \DateTimeImmutable('@'.$registeredAt->getTimestamp()),
        );
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientName(): ?string
    {
        return $this->clientName;
    }

    /**
     * @return list<string> exactly as registered, in the order given
     */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    /**
     * @return list<OAuthGrantType>
     */
    public function grantTypes(): array
    {
        return array_map(static fn (string $grantType): OAuthGrantType => OAuthGrantType::from($grantType), $this->grantTypes);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function checkedClientName(string $clientName): string
    {
        $trimmedName = OneLineText::trimmed($clientName);
        if (null === $trimmedName || OneLineText::hasControlLineBreakOrFormat($trimmedName)) {
            throw new InvalidOAuthClientMetadata('A client_name is one line of plain text.');
        }
        if (OneLineText::isBlank($trimmedName)) {
            throw new InvalidOAuthClientMetadata('A client_name has a character that shows; leave it out for none.');
        }
        if (mb_strlen($trimmedName) > self::CLIENT_NAME_MAX_LENGTH) {
            throw new InvalidOAuthClientMetadata(\sprintf('A client_name has at most %d characters.', self::CLIENT_NAME_MAX_LENGTH));
        }

        return $trimmedName;
    }
}
