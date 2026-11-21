<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvalidOAuthClientMetadata;
use App\Identity\Domain\InvalidOAuthRedirectUri;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthGrantType;

/**
 * Dynamic client registration (RFC 7591) of public clients only (ADR-0050): no client secret is ever issued, so a
 * request for any token_endpoint_auth_method but "none" is refused. Metadata this server does not use (logo_uri,
 * client_uri, scope, contacts and the like) is ignored and not stored, as RFC 7591 section 2 allows.
 */
final readonly class RegisterOAuthClient
{
    public const int MAX_REQUEST_BYTES = 16384;
    private const int MAX_JSON_DEPTH = 16;

    public function __construct(private OAuthClientRegistrationLimiter $limiter, private OAuthClients $clients)
    {
    }

    /**
     * Counts the request against the client IP first, so a refused request counts too, then registers the client in
     * the caller's transaction. An absent token_endpoint_auth_method registers "none", an absent grant_types
     * authorization_code alone, an absent response_types "code".
     *
     * @param string $requestBody the request body; over MAX_REQUEST_BYTES is refused, so the caller need read no more
     *                            than one byte over
     *
     * @throws TooManyOAuthClientRegistrations before the body is looked at; nothing is stored
     * @throws OAuthClientRegistrationRefused  when the body is not a JSON object within MAX_REQUEST_BYTES, asks for a
     *                                         client secret, a grant or response type other than the code flow's, or
     *                                         breaks a rule of OAuthClient::register(); nothing is stored
     */
    public function __invoke(string $clientIp, string $requestBody, \DateTimeImmutable $registeredAt): RegisteredOAuthClient
    {
        $this->limiter->consume($clientIp);
        $metadata = self::metadataOf($requestBody);
        self::refuseUnlessPublicClient($metadata);
        self::refuseUnlessCodeResponseType($metadata);
        try {
            $client = OAuthClient::register(self::clientNameOf($metadata), self::redirectUrisOf($metadata), self::grantTypesOf($metadata), $registeredAt);
        } catch (InvalidOAuthRedirectUri $refusal) {
            throw new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_REDIRECT_URI, $refusal->getMessage(), $refusal);
        } catch (InvalidOAuthClientMetadata $refusal) {
            throw new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA, $refusal->getMessage(), $refusal);
        }
        $this->clients->add($client);

        return new RegisteredOAuthClient(
            $client->clientId(),
            $client->createdAt(),
            $client->clientName(),
            $client->redirectUris(),
            array_map(static fn (OAuthGrantType $grantType): string => $grantType->value, $client->grantTypes()),
            [OAuthClient::RESPONSE_TYPE],
            OAuthClient::TOKEN_ENDPOINT_AUTH_METHOD,
        );
    }

    /**
     * @return array<array-key, mixed> the fields of the JSON object
     */
    private static function metadataOf(string $requestBody): array
    {
        if (\strlen($requestBody) > self::MAX_REQUEST_BYTES) {
            throw self::metadataRefusal(\sprintf('A registration request has at most %d bytes.', self::MAX_REQUEST_BYTES));
        }
        try {
            $metadata = json_decode($requestBody, false, self::MAX_JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $malformed) {
            throw new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA, 'A registration request is a JSON object.', $malformed);
        }
        if (!$metadata instanceof \stdClass) {
            throw self::metadataRefusal('A registration request is a JSON object.');
        }

        return get_object_vars($metadata);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function refuseUnlessPublicClient(array $metadata): void
    {
        if (OAuthClient::TOKEN_ENDPOINT_AUTH_METHOD !== ($metadata['token_endpoint_auth_method'] ?? OAuthClient::TOKEN_ENDPOINT_AUTH_METHOD)) {
            throw self::metadataRefusal('Only public clients register here: token_endpoint_auth_method is "none", and no client secret is issued.');
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function refuseUnlessCodeResponseType(array $metadata): void
    {
        $responseTypes = $metadata['response_types'] ?? [OAuthClient::RESPONSE_TYPE];
        if (!\is_array($responseTypes) || [] === $responseTypes) {
            throw self::metadataRefusal('response_types is ["code"].');
        }
        foreach ($responseTypes as $responseType) {
            if (OAuthClient::RESPONSE_TYPE !== $responseType) {
                throw self::metadataRefusal('response_types is ["code"].');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function clientNameOf(array $metadata): ?string
    {
        $clientName = $metadata['client_name'] ?? null;
        if (null !== $clientName && !\is_string($clientName)) {
            throw self::metadataRefusal('client_name is a string.');
        }

        return $clientName;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return list<string>
     */
    private static function redirectUrisOf(array $metadata): array
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (!\is_array($redirectUris) || !array_is_list($redirectUris)) {
            throw new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_REDIRECT_URI, 'redirect_uris is a list of URIs.');
        }
        $checkedRedirectUris = [];
        foreach ($redirectUris as $redirectUri) {
            if (!\is_string($redirectUri)) {
                throw new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_REDIRECT_URI, 'redirect_uris is a list of URIs.');
            }
            $checkedRedirectUris[] = $redirectUri;
        }

        return $checkedRedirectUris;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return list<OAuthGrantType>
     */
    private static function grantTypesOf(array $metadata): array
    {
        $grantTypeNames = $metadata['grant_types'] ?? [OAuthGrantType::AuthorizationCode->value];
        if (!\is_array($grantTypeNames) || !array_is_list($grantTypeNames)) {
            throw self::metadataRefusal('grant_types is a list of authorization_code and refresh_token.');
        }
        $grantTypes = [];
        foreach ($grantTypeNames as $grantTypeName) {
            $grantType = \is_string($grantTypeName) ? OAuthGrantType::tryFrom($grantTypeName) : null;
            if (null === $grantType) {
                throw self::metadataRefusal('grant_types is a list of authorization_code and refresh_token.');
            }
            $grantTypes[] = $grantType;
        }

        return $grantTypes;
    }

    private static function metadataRefusal(string $description): OAuthClientRegistrationRefused
    {
        return new OAuthClientRegistrationRefused(OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA, $description);
    }
}
