<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\OAuthClientRegistrationRefused;
use App\Identity\Infrastructure\Security\ThrottleKeyHasher;
use App\Identity\UI\Controller\OAuthClientRegistrationController;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\LimiterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /oauth/register (RFC 7591, ADR-0050) through the real firewall, rate limiter and database. Each test comes from
 * an IP of its own in 2001:db8::/32 whose counter is reset first, so the limiter of an earlier run never refuses it. The
 * clock is fixed on 6 Nov 2026 with a fraction of a second, which the stored time drops. Registered clients are
 * committed, so they are deleted through the migrator afterwards.
 */
final class OAuthClientRegistrationTest extends WebTestCase
{
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00.600 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    private const string LOOPBACK_CALLBACK = 'http://127.0.0.1:53127/cb';
    private const int REGISTRATIONS_PER_HOUR = 30;

    private KernelBrowser $client;
    private string $clientIp;

    /** @var list<string> */
    private array $registeredClientIds = [];

    protected function setUp(): void
    {
        $this->clientIp = \sprintf('2001:db8::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->registrationLimiter()->reset();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->registeredClientIds) {
            $this->migrator()->executeStatement('DELETE FROM idn_oauth_client WHERE client_id IN (?)', [$this->registeredClientIds], [ArrayParameterType::STRING]);
            $this->registeredClientIds = [];
        }
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aPublicClientRegistersAndGetsItsClientIdWithTheStoredMetadata(): void
    {
        $storedBefore = $this->storedClientCount();

        $this->register([
            'client_name' => '  Claude Code ',
            'redirect_uris' => [self::CALLBACK, self::LOOPBACK_CALLBACK],
            'grant_types' => ['refresh_token', 'authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'logo_uri' => 'https://claude.example/logo.png',
        ]);

        self::assertResponseStatusCodeSame(201);
        $answer = $this->answer();
        $clientId = $answer['client_id'] ?? null;
        self::assertIsString($clientId);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{32}\z/', $clientId);
        self::assertSame([
            'client_id' => $clientId,
            'client_id_issued_at' => new \DateTimeImmutable('2026-11-06 12:00:00 UTC')->getTimestamp(),
            'client_name' => 'Claude Code',
            'redirect_uris' => [self::CALLBACK, self::LOOPBACK_CALLBACK],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $answer);
        $this->assertNeverCached();
        self::assertSame($storedBefore + 1, $this->storedClientCount());
        self::assertSame(
            [
                'client_name' => 'Claude Code',
                'redirect_uris' => '["'.self::CALLBACK.'","'.self::LOOPBACK_CALLBACK.'"]',
                'grant_types' => '["authorization_code","refresh_token"]',
                'created_at' => '2026-11-06 12:00:00.000000',
            ],
            $this->migrator()->fetchAssociative(
                'SELECT client_name, array_to_json(redirect_uris)::text AS redirect_uris, array_to_json(grant_types)::text AS grant_types,'
                ." to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS created_at FROM idn_oauth_client WHERE client_id = ?",
                [$clientId],
            ),
        );
    }

    #[Test]
    public function aClientThatSaysNothingButItsRedirectUriRegistersAsAPublicCodeFlowClientWithoutAName(): void
    {
        $this->register(['redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(201);
        $answer = $this->answer();
        self::assertArrayNotHasKey('client_name', $answer);
        self::assertSame(['authorization_code'], $answer['grant_types'] ?? null);
        self::assertSame(['code'], $answer['response_types'] ?? null);
        self::assertSame('none', $answer['token_endpoint_auth_method'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedRedirectUris(): iterable
    {
        yield 'https' => ['https://claude.example/api/mcp/auth_callback'];
        yield 'http on 127.0.0.1 with a port' => [self::LOOPBACK_CALLBACK];
        yield 'http on localhost' => ['http://localhost/cb'];
        yield "the extension's chromiumapp.org address" => ['https://abcdefghijklmnopabcdefghijklmnop.chromiumapp.org/'];
    }

    #[Test]
    #[DataProvider('acceptedRedirectUris')]
    public function eachAcceptedKindOfRedirectUriRegistersAsGiven(string $redirectUri): void
    {
        $storedBefore = $this->storedClientCount();

        $this->register(['redirect_uris' => [$redirectUri]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([$redirectUri], $this->answer()['redirect_uris'] ?? null);
        self::assertSame($storedBefore + 1, $this->storedClientCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedRedirectUris(): iterable
    {
        yield 'http on a host that is not this computer' => [
            'http://example.com/cb',
            'An http redirect URI points to this computer: localhost, 127.0.0.1 or [::1]. Use https otherwise.',
        ];
        yield 'a custom scheme' => [
            'myapp://cb',
            'A redirect URI starts with https://, or with http:// on localhost, 127.0.0.1 or [::1], and has a host, no user name or password, no space and only valid percent-encoding.',
        ];
        yield 'a fragment' => ['https://x/cb#frag', 'A redirect URI has no fragment (#).'];
        yield 'a chromiumapp.org address whose id has a letter Chrome never uses' => [
            'https://abcdefghijklmnopabcdefghijklmnoz.chromiumapp.org/',
            "A chromiumapp.org redirect URI is https://<the extension's 32-letter id>.chromiumapp.org/, with no port.",
        ];
    }

    #[Test]
    #[DataProvider('refusedRedirectUris')]
    public function eachRefusedKindOfRedirectUriIsRefusedAndNothingIsStored(string $redirectUri, string $expectedDescription): void
    {
        $storedBefore = $this->storedClientCount();

        $this->register(['client_name' => 'Claude Code', 'redirect_uris' => [self::CALLBACK, $redirectUri]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => OAuthClientRegistrationRefused::INVALID_REDIRECT_URI, 'error_description' => $expectedDescription], $this->answer());
        $this->assertNeverCached();
        self::assertSame($storedBefore, $this->storedClientCount());
    }

    #[Test]
    public function aClientAskingForASecretIsRefusedAndNothingIsStored(): void
    {
        $storedBefore = $this->storedClientCount();

        $this->register(['redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => 'client_secret_basic']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'error' => OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA,
            'error_description' => 'Only public clients register here: token_endpoint_auth_method is "none", and no client secret is issued.',
        ], $this->answer());
        self::assertSame($storedBefore, $this->storedClientCount());
    }

    #[Test]
    public function fiveRedirectUrisRegisterAndSixAreRefused(): void
    {
        $fiveRedirectUris = array_map(static fn (int $port): string => \sprintf('http://127.0.0.1:%d/cb', $port), range(53121, 53125));
        $storedBefore = $this->storedClientCount();

        $this->register(['redirect_uris' => [...$fiveRedirectUris, 'http://127.0.0.1:53126/cb']]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => OAuthClientRegistrationRefused::INVALID_REDIRECT_URI, 'error_description' => 'A client has at most 5 redirect URIs.'], $this->answer());
        self::assertSame($storedBefore, $this->storedClientCount());

        $this->register(['redirect_uris' => $fiveRedirectUris]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($fiveRedirectUris, $this->answer()['redirect_uris'] ?? null);
    }

    #[Test]
    public function aClientNameOfAHundredCharactersRegistersAndOneMoreIsRefused(): void
    {
        $storedBefore = $this->storedClientCount();

        $this->register(['client_name' => str_repeat('é', 101), 'redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA, 'error_description' => 'A client_name has at most 100 characters.'], $this->answer());
        self::assertSame($storedBefore, $this->storedClientCount());

        $this->register(['client_name' => str_repeat('é', 100), 'redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(str_repeat('é', 100), $this->answer()['client_name'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNotAJsonObject(): iterable
    {
        yield 'malformed JSON' => ['{"redirect_uris": ["https://claude.example/cb"]'];
        yield 'an empty body' => [''];
        yield 'a JSON array' => ['["https://claude.example/cb"]'];
        yield 'JSON nested deeper than the decoder reads' => [str_repeat('[', 40).str_repeat(']', 40)];
    }

    #[Test]
    #[DataProvider('bodiesThatAreNotAJsonObject')]
    public function aBodyThatIsNotAJsonObjectIsABadRequestNeverAServerError(string $requestBody): void
    {
        $storedBefore = $this->storedClientCount();

        $this->client->request('POST', '/oauth/register', [], [], ['CONTENT_TYPE' => 'application/json'], $requestBody);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => OAuthClientRegistrationRefused::INVALID_CLIENT_METADATA, 'error_description' => 'A registration request is a JSON object.'], $this->answer());
        self::assertSame($storedBefore, $this->storedClientCount());
    }

    #[Test]
    public function theThirtiethRegistrationInAnHourIsStoredAndTheThirtyFirstIsRefusedWithAWait(): void
    {
        self::assertTrue($this->registrationLimiter()->consume(self::REGISTRATIONS_PER_HOUR - 1)->isAccepted());
        $this->register(['redirect_uris' => [self::CALLBACK]]);
        self::assertResponseStatusCodeSame(201);
        $storedBefore = $this->storedClientCount();

        $this->register(['redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(429);
        $answer = $this->answer();
        self::assertSame(OAuthClientRegistrationController::TOO_MANY_REQUESTS, $answer['error'] ?? null);
        self::assertIsString($answer['error_description'] ?? null);
        self::assertMatchesRegularExpression('/\AToo many client registrations from this address\. Try again in [0-9]+ minutes\.\z/', $answer['error_description']);
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $retryAfter);
        self::assertGreaterThanOrEqual(60, (int) $retryAfter);
        self::assertLessThanOrEqual(3600, (int) $retryAfter);
        $this->assertNeverCached();
        self::assertSame($storedBefore, $this->storedClientCount());
    }

    #[Test]
    public function aSpentAddressDoesNotHoldBackAnotherOne(): void
    {
        self::assertTrue($this->registrationLimiter()->consume(self::REGISTRATIONS_PER_HOUR)->isAccepted());
        $this->register(['redirect_uris' => [self::CALLBACK]]);
        self::assertResponseStatusCodeSame(429);
        $this->clientIp = \sprintf('2001:db8:1::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
        $this->registrationLimiter()->reset();
        $this->client->setServerParameter('REMOTE_ADDR', $this->clientIp);

        $this->register(['redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(201);
    }

    #[Test]
    public function aLoggedInPersonWithTwoOrganizationsAndNoneChosenRegistersWithoutBeingSentToThePicker(): void
    {
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($user, $this->createOrganization('Acme Foods'));
        $this->addMembership($user, $this->createOrganization('Beta Bakery'));
        $this->logIn($this->client, $user);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/org/choose', 302);

        $this->register(['redirect_uris' => [self::CALLBACK]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([self::CALLBACK], $this->answer()['redirect_uris'] ?? null);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function register(array $metadata): void
    {
        $this->client->request('POST', '/oauth/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $this->rememberRegisteredClient();
    }

    /**
     * Before any assertion, so a failing test still has its client deleted.
     */
    private function rememberRegisteredClient(): void
    {
        $response = $this->client->getResponse();
        if (201 !== $response->getStatusCode()) {
            return;
        }
        $answer = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        if (\is_array($answer) && \is_string($answer['client_id'] ?? null)) {
            $this->registeredClientIds[] = $answer['client_id'];
        }
    }

    /**
     * RFC 7591 section 3.2.1. The session listener may add its own private directives; no-store must stay among them.
     */
    private function assertNeverCached(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
        self::assertSame('no-cache', $headers->get('Pragma'));
    }

    private function storedClientCount(): int
    {
        $count = $this->migrator()->fetchOne('SELECT count(*) FROM idn_oauth_client');
        self::assertIsInt($count);

        return $count;
    }

    private function registrationLimiter(): LimiterInterface
    {
        $keyHasher = self::getContainer()->get(ThrottleKeyHasher::class);

        return self::getContainer()->get('limiter.oauth_client_registration_per_ip')->create($keyHasher->hexOf($this->clientIp));
    }

    /**
     * @return array<string, mixed> the JSON answer
     */
    private function answer(): array
    {
        $response = $this->client->getResponse();
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $answer = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $fields = [];
        foreach ($answer as $name => $value) {
            self::assertIsString($name);
            $fields[$name] = $value;
        }

        return $fields;
    }
}
