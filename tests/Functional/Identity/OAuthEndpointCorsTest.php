<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\UI\OAuthEndpointCors;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * CORS on the OAuth endpoints the browser extension connects through (OAuthEndpointCors), with the test's
 * EXTENSION_ORIGINS (.env.test), and the extension's next step: an access token from POST /oauth/token works on
 * /api/v1. Codes are issued on 20 Mar 2099 with the clock frozen then, as the code resolver compares with the database
 * clock (OAuthTokenTest says why).
 */
final class OAuthEndpointCorsTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;

    private const string NOW = '2099-03-20 09:00:00 UTC';
    private const string API_DAY = '2026-11-17 09:00:00 UTC';
    private const string EXTENSION_ORIGIN = 'chrome-extension://pinroundtestextensionidaaaaaaaaa';
    /** A Chrome extension id is 32 letters from a to p, which the test origin's id is not. */
    private const string CALLBACK = 'https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org/';
    /** The verifier and S256 challenge of RFC 7636 Appendix B. */
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const array PATHS = ['/oauth/register', '/oauth/token', '/oauth/revoke', '/.well-known/oauth-authorization-server'];
    private const array METHODS_BY_PATH = [
        '/oauth/register' => 'POST',
        '/oauth/token' => 'POST',
        '/oauth/revoke' => 'POST',
        '/.well-known/oauth-authorization-server' => 'GET',
    ];
    /** What callFrom() gets from any origin: a registration, a token request without grant_type, an unknown token's
     * revocation and the document. */
    private const array STATUS_BY_PATH = [
        '/oauth/register' => 201,
        '/oauth/token' => 400,
        '/oauth/revoke' => 200,
        '/.well-known/oauth-authorization-server' => 200,
    ];

    private KernelBrowser $client;
    private string $clientId;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->clientId = $this->registerClient($this->client, 'pinround extension', [self::CALLBACK], ['authorization_code', 'refresh_token']);
    }

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function everyOAuthEndpointLetsTheExtensionOriginReadItsAnswerWithoutCredentials(): void
    {
        $answered = [];
        foreach (self::PATHS as $path) {
            $status = $this->callFrom(self::EXTENSION_ORIGIN, $path);

            $answered[$path] = $status;
            $this->assertReadableBy(self::EXTENSION_ORIGIN, $path);
        }

        self::assertSame(self::STATUS_BY_PATH, $answered);
    }

    #[Test]
    public function aPreflightFromTheExtensionOriginIsAnsweredWithTheEndpointsMethodAndContentType(): void
    {
        foreach (self::PATHS as $path) {
            $this->client->request('OPTIONS', $path, server: [
                'HTTP_ORIGIN' => self::EXTENSION_ORIGIN,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => self::METHODS_BY_PATH[$path],
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
            ]);

            self::assertResponseStatusCodeSame(204, $path);
            $headers = $this->client->getResponse()->headers;
            self::assertSame([self::METHODS_BY_PATH[$path], OAuthEndpointCors::ALLOWED_HEADERS, '600'], [$headers->get('Access-Control-Allow-Methods'), $headers->get('Access-Control-Allow-Headers'), $headers->get('Access-Control-Max-Age')], $path);
            $this->assertReadableBy(self::EXTENSION_ORIGIN, $path);
        }
    }

    /**
     * REACH: an origin that is not in EXTENSION_ORIGINS, also one that differs from it only by case or a trailing
     * slash, gets an answer it cannot read and no preflight.
     */
    #[Test]
    public function anyOtherOriginGetsNoCorsHeaderAndNoPreflight(): void
    {
        $otherOrigins = [
            'chrome-extension://someotherextensionidbbbbbbbbbbb',
            'https://evil.example',
            'null',
            strtoupper(self::EXTENSION_ORIGIN),
            self::EXTENSION_ORIGIN.'/',
        ];
        foreach ($otherOrigins as $origin) {
            foreach (self::PATHS as $path) {
                $status = $this->callFrom($origin, $path);

                self::assertSame(self::STATUS_BY_PATH[$path], $status, $origin.' '.$path);
                $this->assertNotReadableBy($origin, $path);

                $this->client->request('OPTIONS', $path, server: ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => self::METHODS_BY_PATH[$path]]);

                self::assertResponseStatusCodeSame(405, $origin.' '.$path);
                self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Methods'), $origin.' '.$path);
                $this->assertNotReadableBy($origin, $path);
            }
        }
    }

    #[Test]
    public function anAccessTokenTradedByTheExtensionWorksOnTheRestApiAsThePersonInThePickedOrganization(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        $code = $this->issueAuthorizationCode($omar, $betaBakery, $this->clientId, self::CALLBACK, self::CHALLENGE, [ApiTokenScope::Read], new \DateTimeImmutable(self::NOW));

        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $this->clientId, 'code_verifier' => self::VERIFIER], server: ['HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->assertReadableBy(self::EXTENSION_ORIGIN, '/oauth/token');
        $accessToken = $this->answer()['access_token'] ?? null;
        self::assertIsString($accessToken);
        // The audit log /api/v1 writes has no partition in 2099; the token stays live, as its expiry in 2099 is compared
        // with the database clock.
        $this->clock->modify(self::API_DAY);

        $this->client->request('GET', '/api/v1/_test/token-probe', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::EXTENSION_ORIGIN, $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame(
            ['userId' => $omar->id()->toString(), 'organizationId' => $betaBakery->toString(), 'scopes' => ['read']],
            array_intersect_key($this->answer(), ['userId' => true, 'organizationId' => true, 'scopes' => true]),
        );

        $this->client->request('GET', '/api/v1/me/tickets', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['tickets' => [], 'has_more' => false], array_intersect_key($this->answer(), ['tickets' => true, 'has_more' => true]));
    }

    /**
     * @return int the answer's status
     */
    private function callFrom(string $origin, string $path): int
    {
        $server = ['HTTP_ORIGIN' => $origin];
        if ('/oauth/register' === $path) {
            $this->client->request('POST', $path, [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], json_encode(['client_name' => 'Another extension', 'redirect_uris' => [self::CALLBACK]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } elseif ('/oauth/revoke' === $path) {
            $this->client->request('POST', $path, ['token' => 'pr_unknown', 'client_id' => $this->clientId], server: $server);
        } else {
            $this->client->request(self::METHODS_BY_PATH[$path], $path, server: $server);
        }
        $status = $this->client->getResponse()->getStatusCode();
        if (201 === $status) {
            $clientId = $this->answer()['client_id'] ?? null;
            self::assertIsString($clientId);
            $this->registeredClientIds[] = $clientId;
        }

        return $status;
    }

    private function assertReadableBy(string $origin, string $path): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame($origin, $headers->get('Access-Control-Allow-Origin'), $path);
        self::assertContains('Origin', $this->client->getResponse()->getVary(), $path);
        self::assertFalse($headers->has('Access-Control-Allow-Credentials'), $path);
    }

    private function assertNotReadableBy(string $origin, string $path): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertFalse($headers->has('Access-Control-Allow-Origin'), $origin.' '.$path);
        self::assertFalse($headers->has('Access-Control-Expose-Headers'), $origin.' '.$path);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function answer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }
}
