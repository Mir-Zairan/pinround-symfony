<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\OAuthDiscovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthDiscoveryTest extends TestCase
{
    private const string ISSUER = 'https://pinround.acme-foods.example:8443';

    #[Test]
    public function theAuthorizationServerMetadataNamesEveryEndpointUnderTheIssuerAndOnlyThePublicCodeFlow(): void
    {
        self::assertSame(
            [
                'issuer' => 'https://pinround.acme-foods.example:8443',
                'authorization_endpoint' => 'https://pinround.acme-foods.example:8443/oauth/authorize',
                'token_endpoint' => 'https://pinround.acme-foods.example:8443/oauth/token',
                'registration_endpoint' => 'https://pinround.acme-foods.example:8443/oauth/register',
                'revocation_endpoint' => 'https://pinround.acme-foods.example:8443/oauth/revoke',
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
                'revocation_endpoint_auth_methods_supported' => ['none'],
            ],
            new OAuthDiscovery()->authorizationServerMetadata(self::ISSUER),
        );
    }

    #[Test]
    public function theProtectedResourceMetadataNamesMcpAndTheIssuerAsItsServer(): void
    {
        self::assertSame(
            [
                'resource' => 'https://pinround.acme-foods.example:8443/mcp',
                'authorization_servers' => ['https://pinround.acme-foods.example:8443'],
            ],
            new OAuthDiscovery()->protectedResourceMetadata(self::ISSUER),
        );
    }
}
