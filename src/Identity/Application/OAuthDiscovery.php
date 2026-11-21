<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;

/**
 * The discovery documents of pinround's OAuth authorization server (ADR-0050), so an MCP client finds it from /mcp
 * on its own: the authorization server metadata (RFC 8414) and the protected resource metadata of /mcp (RFC 9728).
 * The paths of the endpoints are fixed here; the issuer is the site's own origin.
 */
final readonly class OAuthDiscovery
{
    public const string AUTHORIZATION_PATH = '/oauth/authorize';
    public const string TOKEN_PATH = '/oauth/token';
    public const string REGISTRATION_PATH = '/oauth/register';
    public const string REVOCATION_PATH = '/oauth/revoke';
    public const string AUTHORIZATION_SERVER_METADATA_PATH = '/.well-known/oauth-authorization-server';
    public const string PROTECTED_RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';
    public const string PROTECTED_RESOURCE_PATH = '/mcp';

    /**
     * @param string $issuer the site's origin, scheme and host with any port and no trailing slash
     *
     * @return array<string, string|list<string>>
     */
    public function authorizationServerMetadata(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.self::AUTHORIZATION_PATH,
            'token_endpoint' => $issuer.self::TOKEN_PATH,
            'registration_endpoint' => $issuer.self::REGISTRATION_PATH,
            'revocation_endpoint' => $issuer.self::REVOCATION_PATH,
            'response_types_supported' => [OAuthClient::RESPONSE_TYPE],
            'grant_types_supported' => array_map(static fn (OAuthGrantType $grantType): string => $grantType->value, OAuthGrantType::cases()),
            'code_challenge_methods_supported' => [OAuthClient::CODE_CHALLENGE_METHOD],
            'token_endpoint_auth_methods_supported' => [OAuthClient::TOKEN_ENDPOINT_AUTH_METHOD],
            // RFC 8414 reads an absent list as client_secret_basic, which no client here has.
            'revocation_endpoint_auth_methods_supported' => [OAuthClient::TOKEN_ENDPOINT_AUTH_METHOD],
        ];
    }

    /**
     * @param string $issuer the site's origin, as for authorizationServerMetadata()
     *
     * @return array<string, string|list<string>>
     */
    public function protectedResourceMetadata(string $issuer): array
    {
        return [
            'resource' => $issuer.self::PROTECTED_RESOURCE_PATH,
            'authorization_servers' => [$issuer],
        ];
    }
}
