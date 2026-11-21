<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\GetRoundNotes;
use App\AgentApi\Infrastructure\Mcp\Tool\GetTicket;
use App\AgentApi\Infrastructure\Mcp\Tool\ListDraftScreenshots;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\AgentApi\Tool\SeedsRetakeTargets;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * An access token from POST /oauth/token (ADR-0050) is held to its organization on /api/v1 and /mcp as a pasted
 * personal token is (phase 14, T20). Acme Foods' SHOP-2 is Mia's report assigned to Omar, a member of Shop, with Omar's
 * draft holding "Cart total"; Beta Bakery's SHOP-1, a key Acme Foods has no ticket with, has Omar's draft holding
 * "Bread shelf", and Omar is a member of Beta Bakery too, so only the token's organization can refuse him. The resolvers
 * compare expiry with the database clock, so codes and tokens are issued on 20 Mar 2099; the calls then run on 10 Nov
 * 2026 at 09:00:20, a day an aud_event partition exists for and not today.
 */
final class OAuthTokenTenantIsolationTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RegistersOAuthClients;
    use SeedsRetakeTargets;
    use SeedsTicketRounds;

    private const string TOKENS_ISSUED_AT = '2099-03-20 09:00:00 UTC';
    private const string CALLS_AT = '2026-11-10 09:00:20 UTC';
    private const string CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    /** The verifier and S256 challenge of RFC 7636 Appendix B. */
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const string MCP = '/mcp';
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    private const array REST_NOT_FOUND = ['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'];
    /** The rounds, screenshots, tickets and uploads of the tenant, as stored. */
    private const string STATE_QUERY = "SELECT 'round', id::text, state || ' v' || version FROM rvw_round"
        ." UNION ALL SELECT 'screenshot', id::text, round_id::text || ' ' || position FROM rvw_screenshot"
        ." UNION ALL SELECT 'ticket', id::text, version || ' ' || updated_at::text FROM tkt_ticket"
        ." UNION ALL SELECT 'upload', id::text, coalesce(used_at::text, '-') FROM med_upload_intent ORDER BY 1, 2";

    private KernelBrowser $client;
    private MockClock $clock;
    private string $clientId;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $omarsAcmeDraft;
    private string $acmeShot;
    private OrganizationId $betaBakery;
    private string $betaBakeryShot;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::CALLS_AT);
        self::getContainer()->set('clock', $this->clock);

        $this->people = $this->createAcmeFoodsPeople(null);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = $this->addTodoStep();
        $this->omarsAcmeDraft = $this->addRound($this->addTicket('SHOP-2', number: 2), null, 'draft', 'Omar', null);
        $this->acmeShot = $this->addScreenshot($this->omarsAcmeDraft, $this->addMedia('ready', 'Omar'), 0, 'Cart total', null);

        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $this->betaBakery);
        $this->betaBakeryShot = $this->addOtherOrganizationsDraftScreenshot($this->betaBakery, 'Omar');

        $this->clientId = $this->registerClient($this->client, 'Claude', [self::CALLBACK], ['authorization_code', 'refresh_token']);
    }

    protected function tearDown(): void
    {
        $this->removeOAuthClientsAndCodes();
        $this->removeSeededRounds();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'med_upload_intent'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeApiTokens();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * REACH: the Acme Foods token lists Omar's Acme Foods draft and gets 404 for Beta Bakery's SHOP-1; his Beta Bakery
     * token lists that draft, so the refusal is the organization's and not the ticket's.
     */
    #[Test]
    public function anOAuthAccessTokenForAcmeFoodsListsItsDraftOnTheRestApiAndAnotherOrganizationsTicketIsNotFound(): void
    {
        $acmeFoodsToken = $this->oauthAccessTokenOfOmarIn($this->acmeFoods);

        $this->request($acmeFoodsToken, 'GET', '/api/v1/tickets/SHOP-2/draft/screenshots');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['screenshot_id' => $this->acmeShot, 'number' => 1, 'title' => '<untrusted_text>Cart total</untrusted_text>', 'try_count' => 1]], $this->json()['screenshots'] ?? null);

        $this->request($acmeFoodsToken, 'GET', '/api/v1/tickets/SHOP-1/draft/screenshots');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(self::REST_NOT_FOUND, $this->json());
        $this->assertNoTextOfBetaBakery();

        self::assertEqualsCanonicalizing([[ListDraftScreenshots::NAME, 'ok'], [ListDraftScreenshots::NAME, 'not_found']], $this->toolOutcomesOf($this->acmeFoods));
        self::assertSame([], $this->toolOutcomesOf($this->betaBakery));

        $betaBakeryToken = $this->oauthAccessTokenOfOmarIn($this->betaBakery);
        $this->request($betaBakeryToken, 'GET', '/api/v1/tickets/SHOP-1/draft/screenshots');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['screenshot_id' => $this->betaBakeryShot, 'number' => 1, 'title' => '<untrusted_text>Bread shelf</untrusted_text>', 'try_count' => 1]], $this->json()['screenshots'] ?? null);
    }

    /**
     * REACH: get_ticket and get_round_notes answer SHOP-2 and its draft to the Acme Foods token, and Beta Bakery's SHOP-1
     * and its draft with the not-found refusal, leaving no audit row in Beta Bakery.
     */
    #[Test]
    public function anOAuthAccessTokenForAcmeFoodsGetsItsTicketAndRoundOverMcpAndAnotherOrganizationsAreNotFound(): void
    {
        $token = $this->oauthAccessTokenOfOmarIn($this->acmeFoods);

        $this->callTool($token, GetTicket::NAME, ['key' => 'SHOP-2']);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(
            ['SHOP-2', '<untrusted_text>Cart total shows the wrong sum</untrusted_text>'],
            [$this->responseValueAt('result', 'structuredContent', 'key'), $this->responseValueAt('result', 'structuredContent', 'title')],
        );

        $this->callTool($token, GetTicket::NAME, ['key' => 'SHOP-1']);
        $this->assertMcpNotFound();

        $this->callTool($token, GetRoundNotes::NAME, ['key' => 'SHOP-2']);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(
            [$this->omarsAcmeDraft, $this->acmeShot, '<untrusted_text>Cart total</untrusted_text>'],
            [
                $this->responseValueAt('result', 'structuredContent', 'draft_round', 'round_id'),
                $this->responseValueAt('result', 'structuredContent', 'draft_round', 'screenshots', 0, 'screenshot_id'),
                $this->responseValueAt('result', 'structuredContent', 'draft_round', 'screenshots', 0, 'title'),
            ],
        );

        $this->callTool($token, GetRoundNotes::NAME, ['key' => 'SHOP-1']);
        $this->assertMcpNotFound();

        self::assertEqualsCanonicalizing(
            [[GetTicket::NAME, 'ok'], [GetTicket::NAME, 'not_found'], [GetRoundNotes::NAME, 'ok'], [GetRoundNotes::NAME, 'not_found']],
            $this->toolOutcomesOf($this->acmeFoods),
        );
        self::assertSame([], $this->toolOutcomesOf($this->betaBakery));
    }

    /**
     * REACH: a screenshot for Beta Bakery's SHOP-1, through /api/v1 and through add_screenshot on /mcp, is not found and
     * changes neither organization's rounds, screenshots, tickets or uploads; the same upload then goes onto Acme Foods'
     * SHOP-2, so the refusal is not the upload's or the scope's.
     */
    #[Test]
    public function anOAuthAccessTokenForAcmeFoodsAddsNoScreenshotToAnotherOrganizationsTicketAndNothingChanges(): void
    {
        $token = $this->oauthAccessTokenOfOmarIn($this->acmeFoods);
        $picture = ['upload_id' => $this->addReadyUpload(), 'title' => 'Moved here', 'summary' => 'The cart with its total.'];
        $acmeFoodsBefore = $this->stateOf($this->acmeFoods);
        $betaBakeryBefore = $this->stateOf($this->betaBakery);
        self::assertContains($this->betaBakeryShot, array_column($betaBakeryBefore, 1), 'Beta Bakery has no screenshot, so an unchanged state proves nothing.');

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-1/draft/screenshots', $picture);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(self::REST_NOT_FOUND, $this->json());
        $this->assertNoTextOfBetaBakery();

        $this->callTool($token, AddScreenshot::NAME, ['key' => 'SHOP-1', ...$picture]);
        $this->assertMcpNotFound();

        self::assertSame($acmeFoodsBefore, $this->stateOf($this->acmeFoods));
        self::assertSame($betaBakeryBefore, $this->stateOf($this->betaBakery));

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-2/draft/screenshots', $picture);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([AddScreenshot::ADDED, $this->omarsAcmeDraft], [$this->json()['status'] ?? null, $this->json()['round_id'] ?? null]);
        self::assertCount(2, $this->screenshotRowsOf($this->omarsAcmeDraft));
        self::assertSame($betaBakeryBefore, $this->stateOf($this->betaBakery));

        self::assertEqualsCanonicalizing(
            [[AddScreenshot::NAME, 'not_found'], [AddScreenshot::NAME, 'not_found'], [AddScreenshot::NAME, 'ok']],
            $this->toolOutcomesOf($this->acmeFoods),
        );
        self::assertSame([], $this->toolOutcomesOf($this->betaBakery));
    }

    /**
     * Trades a code issued as the consent page issues it at POST /oauth/token, on 20 Mar 2099, then turns the clock back
     * to the day of the calls.
     *
     * @return string the plain access token, for read and rounds:write
     */
    private function oauthAccessTokenOfOmarIn(OrganizationId $organizationId): string
    {
        $this->clock->modify(self::TOKENS_ISSUED_AT);
        $code = $this->issueAuthorizationCode($this->people['Omar'], $organizationId, $this->clientId, self::CALLBACK, self::CHALLENGE, [ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $this->clock->now());

        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CALLBACK, 'client_id' => $this->clientId, 'code_verifier' => self::VERIFIER]);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $answer = $this->json();
        self::assertSame(['Bearer', ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS, 'read rounds:write'], [$answer['token_type'] ?? null, $answer['expires_in'] ?? null, $answer['scope'] ?? null]);
        self::assertIsString($answer['access_token'] ?? null);
        $this->clock->modify(self::CALLS_AT);

        return $answer['access_token'];
    }

    /**
     * Starts a session as Claude Code does, then calls the tool in it.
     *
     * @param array<string, string> $arguments
     */
    private function callTool(string $token, string $toolName, array $arguments): void
    {
        $this->postJsonRpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $toolName, 'arguments' => $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request(
            'POST',
            self::MCP,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => self::ACCEPT, 'HTTP_AUTHORIZATION' => 'Bearer '.$token, ...$sessionHeaders],
            content: json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string>|null $body
     */
    private function request(string $token, string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function assertMcpNotFound(): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => ToolRefusal::NOT_FOUND]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
        $this->assertNoTextOfBetaBakery();
    }

    private function assertNoTextOfBetaBakery(): void
    {
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['Bread', $this->betaBakeryShot] as $betaBakeryText) {
            self::assertStringNotContainsString($betaBakeryText, $body);
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string|int ...$path): mixed
    {
        $value = $this->json();
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * @return list<list<mixed>> each tool call's tool and outcome; the calls share the frozen time, so their order is not
     *                           defined
     */
    private function toolOutcomesOf(OrganizationId $organizationId): array
    {
        return $this->rowsIn($organizationId, "SELECT data->>'tool', data->>'outcome' FROM aud_event WHERE data->>'tool' IS NOT NULL ORDER BY at, id");
    }

    /**
     * @return list<list<mixed>>
     */
    private function stateOf(OrganizationId $organizationId): array
    {
        return $this->rowsIn($organizationId, self::STATE_QUERY);
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsIn(OrganizationId $organizationId, string $sql): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($sql, &$rows): int {
            $rows = $migrator->fetchAllNumeric($sql);

            return 0;
        });

        return $rows;
    }

    private function addTodoStep(): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $stepId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            $parameters,
        ));

        return $stepId;
    }

    /**
     * An upload Omar began for his Acme Foods draft and completed, with the ready picture its completion and processing
     * made under the intent's id, as Media stores them.
     *
     * @return string the intent's id, which is the picture's
     */
    private function addReadyUpload(): string
    {
        $intentId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $uploader = $this->people['Omar']->id()->toString();
        $draftId = $this->omarsAcmeDraft;
        $storageKey = \sprintf('uploads/%s/%s', $organizationId, $intentId);
        $variants = json_encode(['view' => 'media/'.$intentId.'/view.webp', 'thumb' => 'media/'.$intentId.'/thumb.webp'], \JSON_THROW_ON_ERROR);
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $intentId, $uploader, $draftId, $storageKey, $variants): int|string {
            $migrator->executeStatement(
                "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, target_kind, target_id, max_bytes, mime_allowed, storage_key, expires_at, used_at)
                 VALUES (?, ?, ?, 'screenshot', 'round', ?, 245760, '{image/png}', ?, TIMESTAMPTZ '2026-11-11 09:00:00+00', TIMESTAMPTZ '2026-11-10 09:00:05+00')",
                [$organizationId, $intentId, $uploader, $draftId, $storageKey],
            );

            return $migrator->executeStatement(
                "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
                 VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-10 09:00:05+00', 'ready', NULL, ?, 1280, 800, CAST(? AS jsonb))",
                [$organizationId, $intentId, $storageKey, $uploader, hash('sha256', $intentId), $variants],
            );
        });

        return $intentId;
    }
}
