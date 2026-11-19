<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi;

use App\AgentApi\Infrastructure\Mcp\GatedToolCallHandler;
use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\McpSessionBinding;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\GetRoundNotes;
use App\AgentApi\Infrastructure\Mcp\Tool\GetTicket;
use App\AgentApi\Infrastructure\Mcp\Tool\ListMyTickets;
use App\AgentApi\Infrastructure\Mcp\Tool\SetVerdict;
use App\AgentApi\Infrastructure\Mcp\Tool\WhoAmI;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Fixtures\AgentApi\WriteProbeTool;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Integration\Tenancy\TenantProbeTable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /mcp end to end (ADR-0021, P09.T2): the bundle's endpoint behind the api_token firewall, McpRequestGuard and
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
    /** A tool call writes an audit row, and aud_event has partitions for months around the build, none in 2099. */
    private const string TOOL_CALL_TIME = '2026-11-10 09:00:20 UTC';
    private const string OMAR = 'Omar Haddad';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    /** JSON-RPC's "Invalid Request". */
    private const int INVALID_REQUEST = -32600;
    /** The minute of NOW since the epoch: (4077680420 - 20) / 60. */
    private const int MINUTE_OF_NOW = 67961340;
    /** The minute of TOOL_CALL_TIME since the epoch: (1794301220 - 20) / 60. */
    private const int MINUTE_OF_TOOL_CALL_TIME = 29905020;
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    private const int CALLS_PER_TOKEN_MINUTE = 60;
    private const int TOKENS_AT_FULL_RATE_PER_ORGANIZATION = 10;
    /** IssuesApiTokens' LIVE_TOKEN_ISSUED_AT, 2099-03-15 10:00 UTC, plus ApiToken::DEFAULT_LIFETIME_DAYS (90). */
    private const string LIVE_TOKEN_EXPIRES_AT = '2099-06-13T10:00:00+00:00';

    private KernelBrowser $client;
    private MockClock $clock;

    public static function setUpBeforeClass(): void
    {
        self::probeTable()->recreate();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::probeTable()->drop();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        self::probeTable()->removeAllRows();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * WriteProbeTool is registered in the test env only (when@test in config/services.yaml); every other tool listed is
     * one of the product.
     */
    #[Test]
    public function testALiveTokenStartsASessionAndListsTheProductsToolsBesideTheTestProbe(): void
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
        $tools = $this->responseValueAt('result', 'tools');
        self::assertIsArray($tools);
        $toolsByName = array_column($tools, null, 'name');
        self::assertEqualsCanonicalizing([WhoAmI::NAME, ListMyTickets::NAME, GetTicket::NAME, GetRoundNotes::NAME, SetVerdict::NAME, CreateUpload::NAME, AddScreenshot::NAME, WriteProbeTool::NAME], array_keys($toolsByName));
        self::assertCount(8, $tools);
        $whoAmI = $toolsByName[WhoAmI::NAME];
        self::assertIsArray($whoAmI);
        self::assertSame(WhoAmI::NAME, $whoAmI['name'] ?? null);
        self::assertSame(['type' => 'object', 'properties' => [], 'additionalProperties' => false], $whoAmI['inputSchema'] ?? null);
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
            $otherTokenOfAcmeFoods = new ApiTokenAccess(Uuid::v7()->toRfc4122(), $acmeFoods, ['read'], new \DateTimeImmutable('2099-06-18 09:00:20 UTC'));
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

    #[Test]
    public function whoAmIWithAReadTokenAnswersTheTokensIdentityAndWritesOneOkRowWithoutSecrets(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WhoAmI::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        $identity = ['user_id' => $omar->id()->toString(), 'display_name' => self::OMAR, 'organization_id' => $acmeFoods->toString(), 'scopes' => ['read', 'rounds:write'], 'expires_at' => self::LIVE_TOKEN_EXPIRES_AT];
        self::assertSame($identity, $this->responseValueAt('result', 'structuredContent'));
        self::assertSame([['type' => 'text', 'text' => json_encode($identity, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]], $this->responseValueAt('result', 'content'));
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'ok')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, self::displayPrefixOf($token), self::OMAR]);
    }

    #[Test]
    public function aTokenWithoutTheReadScopeGetsAnErrorResultAndOneMissingScopeRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        $omar = $this->createUserWithoutOrganization(self::OMAR);
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$tokenId, $token] = $this->issueApiTokenWithScopes($omar, $acmeFoods, [ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WhoAmI::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => \sprintf(ToolRefusal::MISSING_SCOPE, 'read')]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'), 'a refused call answered the identity');
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'missing_scope')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, self::displayPrefixOf($token), self::OMAR]);
    }

    #[Test]
    public function anUnknownToolGetsAnInvalidParamsErrorAndNoRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, 'delete_every_round', new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_PARAMS, 'message' => GatedToolCallHandler::UNKNOWN_TOOL], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function argumentsTheInputSchemaRefusesGetAnInvalidParamsErrorAndOneInvalidInputRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WhoAmI::NAME, ['round' => 'SHOP-1']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::INVALID_PARAMS, $this->responseValueAt('error', 'code'));
        $message = $this->responseValueAt('error', 'message');
        self::assertIsString($message);
        self::assertStringStartsWith(\sprintf(ToolRefusal::INVALID_INPUT, ''), $message);
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'invalid_input')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, self::displayPrefixOf($token), self::OMAR, 'SHOP-1']);
    }

    /**
     * The SDK refuses them while it reads the request, so the gate never sees the call: its own "Invalid Request".
     */
    #[Test]
    public function argumentsThatAreNoObjectGetTheSdksInvalidRequestErrorAndNoRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WhoAmI::NAME, 'SHOP-1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_REQUEST, 'message' => 'Parameter "arguments" must be an array.'], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    /**
     * Omar works in both; the token is Beta Bakery's, the organization he joined last, so an answer from his first
     * membership differs from the right one.
     */
    #[Test]
    public function whoAmIAnswersTheTokensOrganizationOnlyAndAuditsItThere(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        $omar = $this->createUserWithoutOrganization(self::OMAR);
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        [$tokenId, $token] = $this->issueApiToken($omar, $betaBakery);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WhoAmI::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($betaBakery->toString(), $this->responseValueAt('result', 'structuredContent', 'organization_id'));
        self::assertStringNotContainsString($acmeFoods->toString(), $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'ok')], $this->auditRowsOf($betaBakery));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function aToolThatWritesAndAnswersKeepsItsWriteAndOneOkRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WriteProbeTool::NAME, ['label' => 'kept', 'fail' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => 'written']], $this->responseValueAt('result', 'content'));
        self::assertSame(['kept'], $this->committedProbeLabels($acmeFoods));
        self::assertSame([$this->writeProbeRow($omar, $tokenId, $acmeFoods, 'ok')], $this->auditRowsOf($acmeFoods));
    }

    /**
     * The tool's write and its failure run in one request whose transaction commits, so only the gate's savepoint can
     * take the write back.
     */
    #[Test]
    public function aToolThatFailsAfterItsWriteAnswersToolFailedKeepsNoneOfItAndOneErrorRow(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, WriteProbeTool::NAME, ['label' => 'thrown-away', 'fail' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => GatedToolCallHandler::TOOL_FAILED]], $this->responseValueAt('result', 'content'));
        self::assertSame([], $this->committedProbeLabels($acmeFoods));
        self::assertSame([$this->writeProbeRow($omar, $tokenId, $acmeFoods, 'error')], $this->auditRowsOf($acmeFoods));
    }

    /**
     * Both tokens are Omar's, in Acme Foods, so only the token tells them apart. The write probe would leave a row if
     * it ran; the DELETE would end the session for both if it reached the SDK.
     */
    #[Test]
    public function aSessionStartedWithOneTokenIsNotFoundForAnotherTokenOfTheSameUser(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        [$otherTokenId, $otherToken] = $this->issueApiToken($omar, $acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($otherToken, $sessionId, WriteProbeTool::NAME, ['label' => 'other-token', 'fail' => false]);
        $this->assertSessionNotFound();
        $this->deleteSession($otherToken, $sessionId);
        $this->assertSessionNotFound();

        self::assertSame(2, $this->callsCounted('mcp_calls_per_token_minute', $otherTokenId, self::MINUTE_OF_TOOL_CALL_TIME), 'the binding runs after ToolCallLimiter, so each refused request spends a call');
        self::assertSame(2, $this->callsCounted('mcp_calls_per_token_minute', $tokenId, self::MINUTE_OF_TOOL_CALL_TIME));
        self::assertSame(4, $this->callsCounted('mcp_calls_per_organization_minute', $acmeFoods->toString(), self::MINUTE_OF_TOOL_CALL_TIME));

        self::assertSame([], $this->committedProbeLabels($acmeFoods));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
        $this->callTool($token, $sessionId, WriteProbeTool::NAME, ['label' => 'own-token', 'fail' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['own-token'], $this->committedProbeLabels($acmeFoods));
        self::assertSame([$this->writeProbeRow($omar, $tokenId, $acmeFoods, 'ok')], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function aToolCallWithARealSessionAndNoTokenGets401AndRunsNoTool(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);

        $this->callTool(null, $sessionId, WriteProbeTool::NAME, ['label' => 'no-token', 'fail' => false]);

        $this->assertRefusedByTheFirewallBeforeAnyToolRan($acmeFoods, $tokenId);
    }

    #[Test]
    public function aToolCallWithARealSessionAndItsRevokedTokenGets401AndRunsNoTool(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);
        $this->revokeApiToken($tokenId, $acmeFoods);

        $this->callTool($token, $sessionId, WriteProbeTool::NAME, ['label' => 'revoked-token', 'fail' => false]);

        $this->assertRefusedByTheFirewallBeforeAnyToolRan($acmeFoods, $tokenId);
    }

    /**
     * TokenAuthenticator reads expiry on the database clock, which the frozen clock does not move, so the expired
     * token is another of Omar's, sent with the session his live token started.
     */
    #[Test]
    public function aToolCallWithARealSessionAndAnExpiredTokenGets401AndRunsNoTool(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        [$expiredTokenId, $expiredToken] = $this->issueApiToken($omar, $acmeFoods, '2020-01-01 10:00:00 UTC', 1);
        $sessionId = $this->startSession($token);

        $this->callTool($expiredToken, $sessionId, WriteProbeTool::NAME, ['label' => 'expired-token', 'fail' => false]);

        $this->assertRefusedByTheFirewallBeforeAnyToolRan($acmeFoods, $tokenId);
        self::assertNull($this->counter('mcp_calls_per_token_minute', $expiredTokenId, self::MINUTE_OF_TOOL_CALL_TIME), 'the expired token spent a call');
    }

    #[Test]
    public function aSessionStartedInOneOrganizationIsNotFoundForTheSameUsersTokenInAnother(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        $omar = $this->createUserWithoutOrganization(self::OMAR);
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $acmeFoods);
        $this->addMembership($omar, $betaBakery);
        [, $acmeToken] = $this->issueApiToken($omar, $acmeFoods);
        [, $betaToken] = $this->issueApiToken($omar, $betaBakery);
        $sessionId = $this->startSession($acmeToken);

        $this->callTool($betaToken, $sessionId, WriteProbeTool::NAME, ['label' => 'other-organization', 'fail' => false]);

        $this->assertSessionNotFound();
        self::assertSame([], $this->committedProbeLabels($betaBakery));
        self::assertSame([], $this->committedProbeLabels($acmeFoods));
        self::assertSame([], $this->auditRowsOf($betaBakery));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function aSessionIdNoInitializeStartedIsNotFound(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods();
        $this->startSession($token);

        $this->callTool($token, Uuid::v4()->toRfc4122(), WriteProbeTool::NAME, ['label' => 'unknown-session', 'fail' => false]);

        $this->assertSessionNotFound();
        self::assertSame([], $this->committedProbeLabels($acmeFoods));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    /**
     * The binding's TTL is cut to a minute before the call, so only the call's refresh can bring it back to an hour.
     */
    #[Test]
    public function theTokenThatStartedTheSessionIsAnsweredAndRefreshesTheBindingToAnHour(): void
    {
        $this->clock->modify(self::TOOL_CALL_TIME);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods();
        $sessionId = $this->startSession($token);
        $bindingKey = $this->bindingKeyOf($sessionId);
        self::assertTrue($this->stateValkey()->expire($bindingKey, 60));

        $this->callTool($token, $sessionId, WhoAmI::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'ok')], $this->auditRowsOf($acmeFoods));
        $ttlSeconds = $this->stateValkey()->ttl($bindingKey);
        self::assertIsInt($ttlSeconds);
        self::assertGreaterThan(McpSessionBinding::TTL_SECONDS - 60, $ttlSeconds, 'the call did not refresh the binding');
        self::assertLessThanOrEqual(McpSessionBinding::TTL_SECONDS, $ttlSeconds);
    }

    /**
     * @return array{OrganizationId, string, string, User} Acme Foods, Omar's token id, its plain text and Omar
     */
    private function omarsTokenInAcmeFoods(): array
    {
        $omar = $this->createUserWithoutOrganization(self::OMAR);
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        [$tokenId, $token] = $this->issueApiToken($omar, $acmeFoods);

        return [$acmeFoods, $tokenId, $token, $omar];
    }

    /**
     * As IssuesApiTokens::issueApiToken, with scopes of its own instead of Claude Code's defaults.
     *
     * @param list<ApiTokenScope> $scopes
     *
     * @return array{string, string} the token's id and its plain text
     */
    private function issueApiTokenWithScopes(User $user, OrganizationId $organizationId, array $scopes): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $organizationId, $user->id(), 'Claude Code', $scopes, $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($organizationId, fn () => $this->apiTokens()->add($issued->token()));

        return [$issued->token()->id(), $issued->reveal()];
    }

    /**
     * Initializes a session and confirms it, as Claude Code does before its first tools/list or tools/call.
     *
     * @return string the Mcp-Session-Id
     */
    private function startSession(string $token): string
    {
        $this->postJsonRpc($token, self::initializeMessage());
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        return $sessionId;
    }

    /**
     * @param mixed $arguments sent as they are, so a test can send what no schema allows
     */
    private function callTool(?string $token, string $sessionId, string $toolName, mixed $arguments): void
    {
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $toolName, 'arguments' => $arguments]], $sessionId);
    }

    private function deleteSession(string $token, string $sessionId): void
    {
        $this->client->request('DELETE', self::MCP, server: [...self::mcpHeaders($token), 'HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION]);
    }

    /**
     * The session's own two requests (initialize, initialized) are the only calls its token and organization spent.
     */
    private function assertRefusedByTheFirewallBeforeAnyToolRan(OrganizationId $organizationId, string $sessionTokenId): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson());
        self::assertSame([], $this->committedProbeLabels($organizationId));
        self::assertSame([], $this->auditRowsOf($organizationId));
        self::assertSame(2, $this->callsCounted('mcp_calls_per_token_minute', $sessionTokenId, self::MINUTE_OF_TOOL_CALL_TIME));
        self::assertSame(2, $this->callsCounted('mcp_calls_per_organization_minute', $organizationId->toString(), self::MINUTE_OF_TOOL_CALL_TIME));
    }

    private function assertSessionNotFound(): void
    {
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['jsonrpc' => '2.0', 'error' => ['code' => self::INVALID_REQUEST, 'message' => McpSessionBinding::SESSION_NOT_FOUND]], $this->responseJson());
    }

    /**
     * @return string the Valkey key of the session's binding, under the pool's namespace
     */
    private function bindingKeyOf(string $sessionId): string
    {
        $keys = $this->stateValkey()->keys('*mcp_session_binding.'.$sessionId);
        self::assertIsArray($keys);
        self::assertCount(1, $keys, 'the session has no binding of its own');

        return $keys[0];
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    /**
     * @return array{string, string, string, string, string, null, string, array<string, string>}
     */
    private function toolCallRow(User $user, string $tokenId, string $outcome): array
    {
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, ToolCallAuditor::TOOL_TARGET_TYPE, null, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => WhoAmI::NAME]];
    }

    /**
     * @return array{string, string, string, string, string, string, string, array<string, string>}
     */
    private function writeProbeRow(User $user, string $tokenId, OrganizationId $organizationId, string $outcome): array
    {
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, 'organization', $organizationId->toString(), '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => WriteProbeTool::NAME]];
    }

    /**
     * @return list<mixed> the labels of the organization's committed zz_tenant_probe rows
     */
    private function committedProbeLabels(OrganizationId $organizationId): array
    {
        $labels = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use (&$labels): int {
            $labels = $migrator->fetchFirstColumn('SELECT label FROM zz_tenant_probe ORDER BY label');

            return 0;
        });

        return $labels;
    }

    private static function probeTable(): TenantProbeTable
    {
        return new TenantProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, user, token,
     *                                                                                    action, target type, target,
     *                                                                                    time, data by key
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? ORDER BY at, id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[7]);
            $data = json_decode($row[7], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            ksort($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $data];
        }, $rows);
    }

    /**
     * Every column of every row, as text, holds none of $secrets.
     *
     * @param list<string> $secrets
     */
    private function assertNoAuditRowHolds(OrganizationId $organizationId, array $secrets): void
    {
        $rowTexts = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId->toString()]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rowText);
            }
        }
    }

    private static function displayPrefixOf(string $token): string
    {
        return substr($token, 0, \strlen(ApiToken::PLAIN_PREFIX) + ApiToken::DISPLAY_PREFIX_LENGTH);
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

    private function callsCounted(string $limiterId, string $keyOwner, int $minuteSinceEpoch = self::MINUTE_OF_NOW): int
    {
        $window = $this->counter($limiterId, $keyOwner, $minuteSinceEpoch);
        self::assertInstanceOf(Window::class, $window, \sprintf('no %s counter for %s', $limiterId, $keyOwner));

        return $window->getHitCount();
    }

    /**
     * The counter ToolCallLimiter keys by the token or organization id and the clock's minute since the epoch.
     */
    private function counter(string $limiterId, string $keyOwner, int $minuteSinceEpoch = self::MINUTE_OF_NOW): ?Window
    {
        $storage = self::getContainer()->get(ValkeyLimiterStorage::class);
        $state = $storage->fetch($limiterId.'-'.$keyOwner.':'.$minuteSinceEpoch);
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
