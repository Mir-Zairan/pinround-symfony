<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\OAuthClientRegistrationRefused;
use App\Identity\Application\RegisteredOAuthClient;
use App\Identity\Application\RegisterOAuthClient;
use App\Identity\Application\TooManyOAuthClientRegistrations;
use App\Identity\Domain\OAuthGrantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterOAuthClientTest extends TestCase
{
    private const string CLIENT_IP = '2001:db8::7';
    private const string REGISTERED_AT = '2026-11-06 12:00:00.600 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string NOT_AN_OBJECT = 'A registration request is a JSON object.';
    private const string NOT_A_PUBLIC_CLIENT = 'Only public clients register here: token_endpoint_auth_method is "none", and no client secret is issued.';
    private const string NOT_THE_CODE_RESPONSE = 'response_types is ["code"].';
    private const string NOT_THE_GRANT_LIST = 'grant_types is a list of authorization_code and refresh_token.';
    private const string NOT_A_URI_LIST = 'redirect_uris is a list of URIs.';

    private InMemoryOAuthClients $clients;
    private RecordingOAuthClientRegistrationLimiter $limiter;
    private RegisterOAuthClient $registerOAuthClient;

    protected function setUp(): void
    {
        $this->clients = new InMemoryOAuthClients();
        $this->limiter = new RecordingOAuthClientRegistrationLimiter();
        $this->registerOAuthClient = new RegisterOAuthClient($this->limiter, $this->clients);
    }

    #[Test]
    public function aPublicClientIsStoredAndItsMetadataRepeatedWithoutWhatThisServerIgnores(): void
    {
        $registered = $this->register(self::body([
            'client_name' => ' Claude Code ',
            'redirect_uris' => [self::CALLBACK, 'http://127.0.0.1:53127/cb'],
            'grant_types' => ['refresh_token', 'authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'logo_uri' => 'https://claude.example/logo.png',
            'scope' => 'everything',
        ]));

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{32}\z/', $registered->clientId);
        self::assertSame('2026-11-06 12:00:00.000000 +00:00', $registered->issuedAt->format('Y-m-d H:i:s.u P'));
        self::assertSame('Claude Code', $registered->clientName);
        self::assertSame([self::CALLBACK, 'http://127.0.0.1:53127/cb'], $registered->redirectUris);
        self::assertSame(['authorization_code', 'refresh_token'], $registered->grantTypes);
        self::assertSame(['code'], $registered->responseTypes);
        self::assertSame('none', $registered->tokenEndpointAuthMethod);
        $stored = $this->clients->byClientId($registered->clientId);
        self::assertNotNull($stored);
        self::assertSame('Claude Code', $stored->clientName());
        self::assertSame([self::CALLBACK, 'http://127.0.0.1:53127/cb'], $stored->redirectUris());
        self::assertSame([OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken], $stored->grantTypes());
        self::assertCount(1, $this->clients->all());
        self::assertSame([self::CLIENT_IP], $this->limiter->consumedIps);
    }

    #[Test]
    public function absentMetadataRegistersAPublicCodeClientWithoutAName(): void
    {
        $registered = $this->register(self::body(['redirect_uris' => [self::CALLBACK]]));

        self::assertNull($registered->clientName);
        self::assertSame(['authorization_code'], $registered->grantTypes);
        self::assertSame(['code'], $registered->responseTypes);
        self::assertSame('none', $registered->tokenEndpointAuthMethod);
        self::assertNotNull($this->clients->byClientId($registered->clientId));
    }

    #[Test]
    public function aBodyOfExactlyTheMaximumSizeIsAccepted(): void
    {
        $body = self::paddedTo(RegisterOAuthClient::MAX_REQUEST_BYTES, self::body(['redirect_uris' => [self::CALLBACK]]));

        $registered = $this->register($body);

        self::assertSame(RegisterOAuthClient::MAX_REQUEST_BYTES, \strlen($body));
        self::assertNotNull($this->clients->byClientId($registered->clientId));
    }

    #[Test]
    public function twoRegistrationsGetTwoClients(): void
    {
        $first = $this->register(self::body(['redirect_uris' => [self::CALLBACK]]));
        $second = $this->register(self::body(['redirect_uris' => [self::CALLBACK]]));

        self::assertNotSame($first->clientId, $second->clientId);
        self::assertCount(2, $this->clients->all());
        self::assertSame([self::CLIENT_IP, self::CLIENT_IP], $this->limiter->consumedIps);
    }

    #[Test]
    public function aLimitedAddressIsRefusedBeforeItsValidBodyIsStored(): void
    {
        $this->limiter->isRefusing = true;

        try {
            $this->register(self::body(['redirect_uris' => [self::CALLBACK]]));
            self::fail('The registration was accepted.');
        } catch (TooManyOAuthClientRegistrations $refusal) {
            self::assertSame(60, $refusal->minutesToWait);
        }

        self::assertSame([], $this->clients->all());
        self::assertSame([self::CLIENT_IP], $this->limiter->consumedIps);
    }

    #[Test]
    public function aLimitedAddressIsRefusedBeforeItsBodyIsRead(): void
    {
        $this->limiter->isRefusing = true;

        $this->expectException(TooManyOAuthClientRegistrations::class);

        $this->register('not json');
    }

    /**
     * @return iterable<string, array{string, string, string}> the body, the RFC 7591 error and its description
     */
    public static function refusedBodies(): iterable
    {
        $metadata = OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA;
        $redirectUri = OAuthClientRegistrationRefused::INVALID_REDIRECT_URI;

        yield 'malformed JSON' => ['{"redirect_uris": ["'.self::CALLBACK.'"]', $metadata, self::NOT_AN_OBJECT];
        yield 'an empty body' => ['', $metadata, self::NOT_AN_OBJECT];
        yield 'a JSON array' => ['["'.self::CALLBACK.'"]', $metadata, self::NOT_AN_OBJECT];
        yield 'an empty JSON array' => ['[]', $metadata, self::NOT_AN_OBJECT];
        yield 'a JSON string' => ['"'.self::CALLBACK.'"', $metadata, self::NOT_AN_OBJECT];
        yield 'JSON null' => ['null', $metadata, self::NOT_AN_OBJECT];
        yield 'nested past the depth limit' => ['{"redirect_uris": ["'.self::CALLBACK.'"], "x": '.str_repeat('[', 40).str_repeat(']', 40).'}', $metadata, self::NOT_AN_OBJECT];
        yield 'one byte over the maximum size' => [
            self::paddedTo(RegisterOAuthClient::MAX_REQUEST_BYTES + 1, self::body(['redirect_uris' => [self::CALLBACK]])),
            $metadata,
            'A registration request has at most 16384 bytes.',
        ];
        yield 'client_secret_basic' => [self::body(['redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => 'client_secret_basic']), $metadata, self::NOT_A_PUBLIC_CLIENT];
        yield 'client_secret_post' => [self::body(['redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => 'client_secret_post']), $metadata, self::NOT_A_PUBLIC_CLIENT];
        yield 'the auth method not a string' => [self::body(['redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => ['none']]), $metadata, self::NOT_A_PUBLIC_CLIENT];
        yield 'the token response type' => [self::body(['redirect_uris' => [self::CALLBACK], 'response_types' => ['token']]), $metadata, self::NOT_THE_CODE_RESPONSE];
        yield 'code and token response types' => [self::body(['redirect_uris' => [self::CALLBACK], 'response_types' => ['code', 'token']]), $metadata, self::NOT_THE_CODE_RESPONSE];
        yield 'no response type' => [self::body(['redirect_uris' => [self::CALLBACK], 'response_types' => []]), $metadata, self::NOT_THE_CODE_RESPONSE];
        yield 'response_types a string' => [self::body(['redirect_uris' => [self::CALLBACK], 'response_types' => 'code']), $metadata, self::NOT_THE_CODE_RESPONSE];
        yield 'the implicit grant' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => ['authorization_code', 'implicit']]), $metadata, self::NOT_THE_GRANT_LIST];
        yield 'a grant in capitals' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => ['AUTHORIZATION_CODE']]), $metadata, self::NOT_THE_GRANT_LIST];
        yield 'grant_types a string' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => 'authorization_code']), $metadata, self::NOT_THE_GRANT_LIST];
        yield 'grant_types an object' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => ['first' => 'authorization_code']]), $metadata, self::NOT_THE_GRANT_LIST];
        yield 'a grant not a string' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => [1]]), $metadata, self::NOT_THE_GRANT_LIST];
        yield 'no grant' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => []]), $metadata, 'A client uses the authorization_code grant.'];
        yield 'only refresh_token' => [self::body(['redirect_uris' => [self::CALLBACK], 'grant_types' => ['refresh_token']]), $metadata, 'A client uses the authorization_code grant.'];
        yield 'client_name a number' => [self::body(['redirect_uris' => [self::CALLBACK], 'client_name' => 7]), $metadata, 'client_name is a string.'];
        yield 'client_name two lines' => [self::body(['redirect_uris' => [self::CALLBACK], 'client_name' => "Claude\nCode"]), $metadata, 'A client_name is one line of plain text.'];
        yield 'no redirect_uris' => [self::body(['client_name' => 'Claude Code']), $redirectUri, self::NOT_A_URI_LIST];
        yield 'redirect_uris a string' => [self::body(['redirect_uris' => self::CALLBACK]), $redirectUri, self::NOT_A_URI_LIST];
        yield 'redirect_uris an object' => [self::body(['redirect_uris' => ['first' => self::CALLBACK]]), $redirectUri, self::NOT_A_URI_LIST];
        yield 'a redirect URI not a string' => [self::body(['redirect_uris' => [self::CALLBACK, 7]]), $redirectUri, self::NOT_A_URI_LIST];
        yield 'a redirect URI null' => [self::body(['redirect_uris' => [null]]), $redirectUri, self::NOT_A_URI_LIST];
        yield 'an empty redirect_uris' => [self::body(['redirect_uris' => []]), $redirectUri, 'Give at least one redirect URI.'];
        yield 'six redirect URIs' => [
            self::body(['redirect_uris' => array_map(static fn (int $n): string => 'https://claude.example/cb'.$n, range(1, 6))]),
            $redirectUri,
            'A client has at most 5 redirect URIs.',
        ];
        yield 'an http redirect URI off this computer' => [
            self::body(['redirect_uris' => ['http://claude.example/cb']]),
            $redirectUri,
            'An http redirect URI points to this computer: localhost, 127.0.0.1 or [::1]. Use https otherwise.',
        ];
    }

    #[Test]
    #[DataProvider('refusedBodies')]
    public function aRefusedRequestStoresNothingAndStillCounts(string $body, string $error, string $description): void
    {
        try {
            $this->register($body);
            self::fail('The registration was accepted.');
        } catch (OAuthClientRegistrationRefused $refusal) {
            self::assertSame($error, $refusal->error);
            self::assertSame($description, $refusal->getMessage());
        }

        self::assertSame([], $this->clients->all());
        self::assertSame([self::CLIENT_IP], $this->limiter->consumedIps);
    }

    private function register(string $body): RegisteredOAuthClient
    {
        return ($this->registerOAuthClient)(self::CLIENT_IP, $body, new \DateTimeImmutable(self::REGISTERED_AT));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function body(array $metadata): string
    {
        return json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * $json with spaces before its closing brace, to exactly $bytes bytes.
     */
    private static function paddedTo(int $bytes, string $json): string
    {
        return substr($json, 0, -1).str_repeat(' ', $bytes - \strlen($json)).'}';
    }
}
