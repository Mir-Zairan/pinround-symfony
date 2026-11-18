<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /mcp end to end (ADR-0021, P09.T1): the bundle's endpoint behind the api_token firewall, McpRequestGuard and
 * ToolCallLimiter, on the state Valkey's counters and sessions. The client keeps its kernel, so the frozen clock is
 * the one every request reads: 20 seconds into a minute, so a wait counted from the minute's start (60) or from the
 * real time differs from the right one (40). Plain HTTP, so the SDK's own same-origin check sees the origin of
 * DEFAULT_URI (http://localhost in .env.test).
 */
final class McpEndpointTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string MCP = '/mcp';
    private const string TOKEN_PROBE = '/mcp/_test/token-probe';
    private const string NOW = '2099-03-20 09:00:20 UTC';
    /** The minute of NOW since the epoch: (4077680420 - 20) / 60. */
    private const int MINUTE_OF_NOW = 67961340;
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    private const int CALLS_PER_TOKEN_MINUTE = 60;
    private const int TOKENS_AT_FULL_RATE_PER_ORGANIZATION = 10;

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aLiveTokenStartsASessionAndListsNoToolsYet(): void
    {
        [$acmeFoods, $tokenId, $token] = $this->omarsTokenInAcmeFoods();

        $this->postJsonRpc($token, self::initializeMessage());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('pinround', $this->responseValueAt('result', 'serverInfo', 'name'));
        self::assertSame(self::PROTOCOL_VERSION, $this->responseValueAt('result', 'protocolVersion'));
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        self::assertTrue(Uuid::isValid($sessionId), 'the Mcp-Session-Id is no UUID: '.$sessionId);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sessionId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]], $this->responseJson());
        self::assertSame(['last_used_at' => '2099-03-20 09:00:20', 'revoked_at' => null], $this->usageOf($tokenId, $acmeFoods));
        self::assertSame(3, $this->callsCounted('mcp_calls_per_token_minute', $tokenId));
        self::assertSame(3, $this->callsCounted('mcp_calls_per_organization_minute', $acmeFoods->toString()));
    }

    #[Test]
    public function aRequestFromThePublicBaseUrlsOwnOriginIsAnswered(): void
    {
        [, , $token] = $this->omarsTokenInAcmeFoods();

        $this->postJsonRpc($token, self::initializeMessage(), server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('pinround', $this->responseValueAt('result', 'serverInfo', 'name'));
        self::assertTrue($this->client->getResponse()->headers->has('Mcp-Session-Id'));
    }

    /**
     * Each carries Omar's live token, so only the guard can refuse it; it does so before the firewall looks the token
     * up and before any limit is spent.
     */
    #[Test]
    public function aForeignOriginAnUnknownHostOrABodyOverOneMegabyteIsRefusedBeforeTheTokenIsUsed(): void
    {
        [$acmeFoods, $tokenId, $token] = $this->omarsTokenInAcmeFoods();
        $smallBody = json_encode(self::initializeMessage(), \JSON_THROW_ON_ERROR);
        $overTheCap = str_pad($smallBody, McpRequestGuard::MAX_BODY_BYTES + 1);

        // Absolute, as the browser resolves a relative path against the host of the request before.
        $refusedRequests = [
            'foreign origin' => ['http://localhost/mcp', ['HTTP_ORIGIN' => 'https://pages.example.net'], $smallBody, 403, McpRequestGuard::FOREIGN_ORIGIN],
            'unknown host' => ['http://rebound.example.net/mcp', [], $smallBody, 400, McpRequestGuard::UNKNOWN_HOST],
            'body over the cap without Content-Length' => ['http://localhost/mcp', [], $overTheCap, 413, McpRequestGuard::BODY_TOO_LARGE],
            'Content-Length over the cap' => ['http://localhost/mcp', ['CONTENT_LENGTH' => '2097152'], $smallBody, 413, McpRequestGuard::BODY_TOO_LARGE],
        ];
        foreach ($refusedRequests as $case => [$url, $server, $body, $status, $reason]) {
            $this->client->request('POST', $url, server: [...self::mcpHeaders($token), ...$server], content: $body);

            self::assertSame(isset($server['CONTENT_LENGTH']), $this->client->getRequest()->headers->has('Content-Length'), $case.': the browser set a Content-Length of its own');
            self::assertResponseStatusCodeSame($status, $case);
            self::assertSame(['error' => $reason], $this->responseJson(), $case);
            self::assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'), $case.' started a session');
        }

        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($tokenId, $acmeFoods));
        self::assertNull($this->counter('mcp_calls_per_token_minute', $tokenId), 'a refused request spent a call of the token');
    }

    /**
     * The probe is under /mcp, so its calls spend the same limit; the refused call spends none of it.
     */
    #[Test]
    public function aTokenPastSixtyCallsAMinuteGets429UntilTheClocksNextMinute(): void
    {
        [, $tokenId, $token] = $this->omarsTokenInAcmeFoods();
        for ($call = 1; $call <= self::CALLS_PER_TOKEN_MINUTE; ++$call) {
            $this->client->request('POST', self::TOKEN_PROBE, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(200, \sprintf('call %d', $call));
        }

        $this->postJsonRpc($token, self::initializeMessage());

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => ToolCallLimiter::TOKEN_TOO_FAST], $this->responseJson());
        self::assertResponseHeaderSame('Retry-After', '40');
        self::assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'));
        self::assertSame(self::CALLS_PER_TOKEN_MINUTE, $this->callsCounted('mcp_calls_per_token_minute', $tokenId));
    }

    /**
     * Ten tokens of Acme Foods at their full rate spend its minute, so Lena's first call is refused, while Beta Bakery's
     * token still gets through.
     */
    #[Test]
    public function anotherTokenOfAnOrganizationPastSixHundredCallsAMinuteGets429(): void
    {
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $mia = $this->createUserWithoutOrganization('Mia Novak');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($lena, $acmeFoods);
        $this->addMembership($mia, $betaBakery);
        [$lenasTokenId, $lenasToken] = $this->issueApiToken($lena, $acmeFoods);
        [, $miasToken] = $this->issueApiToken($mia, $betaBakery);
        $toolCallLimiter = self::getContainer()->get(ToolCallLimiter::class);
        for ($token = 1; $token <= self::TOKENS_AT_FULL_RATE_PER_ORGANIZATION; ++$token) {
            $otherTokenOfAcmeFoods = new ApiTokenAccess(Uuid::v7()->toRfc4122(), $acmeFoods, ['read']);
            for ($call = 1; $call <= self::CALLS_PER_TOKEN_MINUTE; ++$call) {
                $toolCallLimiter->consume($otherTokenOfAcmeFoods);
            }
        }
        self::assertSame(600, $this->callsCounted('mcp_calls_per_organization_minute', $acmeFoods->toString()));

        $this->postJsonRpc($lenasToken, self::initializeMessage());

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => ToolCallLimiter::ORGANIZATION_TOO_FAST], $this->responseJson());
        self::assertResponseHeaderSame('Retry-After', '40');
        self::assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'));
        self::assertSame(600, $this->callsCounted('mcp_calls_per_organization_minute', $acmeFoods->toString()));
        self::assertSame(1, $this->callsCounted('mcp_calls_per_token_minute', $lenasTokenId));

        $this->postJsonRpc($miasToken, self::initializeMessage());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->callsCounted('mcp_calls_per_organization_minute', $betaBakery->toString()));
    }

    #[Test]
    public function aMissingRevokedOrExpiredTokenGets401AndNoSession(): void
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$revokedTokenId, $revokedToken] = $this->issueApiToken($omar, $acmeFoods);
        $this->revokeApiToken($revokedTokenId, $acmeFoods);
        [$expiredTokenId, $expiredToken] = $this->issueApiToken($omar, $acmeFoods, '2020-01-01 10:00:00 UTC', 1);

        foreach (['missing' => null, 'revoked' => $revokedToken, 'expired' => $expiredToken] as $case => $token) {
            $this->postJsonRpc($token, self::initializeMessage());

            self::assertResponseStatusCodeSame(401, $case);
            self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson(), $case);
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer', $case);
            self::assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'), $case.' started a session');
        }

        self::assertSame(['last_used_at' => null, 'revoked_at' => '2099-03-16 10:00:00'], $this->usageOf($revokedTokenId, $acmeFoods));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($expiredTokenId, $acmeFoods));
        self::assertNull($this->counter('mcp_calls_per_organization_minute', $acmeFoods->toString()), 'a refused request spent a call of the organization');
    }

    #[Test]
    public function aBrowserSessionCookieAloneGets401(): void
    {
        $omar = $this->createUserWithPassword('Omar Haddad');
        // EndExpiredSession measures the session's age on this clock from the wall-clock times the session keeps, so
        // a clock before the wall clock keeps the browser session young; 2099 would end it at once.
        $this->clock->modify('2026-11-10 09:00:20 UTC');
        $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Log in', ['email' => $omar->email()->toString(), 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
        $this->client->request('GET', '/_test/actor');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($omar->id()->toString(), $this->responseValueAt('actor', 'userId'), 'the session cookie does not log Omar in, so nothing was checked');

        $this->postJsonRpc(null, self::initializeMessage());

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson());
        self::assertFalse($this->client->getResponse()->headers->has('Mcp-Session-Id'));
    }

    /**
     * @return array{OrganizationId, string, string} Acme Foods, Omar's token id and its plain text
     */
    private function omarsTokenInAcmeFoods(): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$tokenId, $token] = $this->issueApiToken($omar, $acmeFoods);

        return [$acmeFoods, $tokenId, $token];
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $server
     */
    private function postJsonRpc(?string $token, array $message, ?string $sessionId = null, array $server = []): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request('POST', self::MCP, server: [...self::mcpHeaders($token), ...$sessionHeaders, ...$server], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private static function mcpHeaders(?string $token): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => self::ACCEPT];

        return null === $token ? $headers : [...$headers, 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @return array<string, mixed>
     */
    private static function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ];
    }

    private function callsCounted(string $limiterId, string $keyOwner): int
    {
        $window = $this->counter($limiterId, $keyOwner);
        self::assertInstanceOf(Window::class, $window, \sprintf('no %s counter for %s', $limiterId, $keyOwner));

        return $window->getHitCount();
    }

    /**
     * The counter ToolCallLimiter keys by the token or organization id and the clock's minute.
     */
    private function counter(string $limiterId, string $keyOwner): ?Window
    {
        $storage = self::getContainer()->get(ValkeyLimiterStorage::class);
        $state = $storage->fetch($limiterId.'-'.$keyOwner.':'.self::MINUTE_OF_NOW);
        self::assertTrue(null === $state || $state instanceof Window);

        return $state;
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = $this->responseJson();
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
