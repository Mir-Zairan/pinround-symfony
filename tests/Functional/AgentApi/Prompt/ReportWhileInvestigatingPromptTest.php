<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Prompt;

use App\AgentApi\Infrastructure\Mcp\GatedPromptGetHandler;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The MCP prompt report_while_investigating through POST /mcp (P13.T4, FR-MCP-14, ADR-0045): prompts/list and
 * prompts/get behind the same api_token firewall, limits and session binding as the tools, and one audit row per read
 * with the prompt's name and the outcome only. The clock is frozen at 2026-11-10 09:00:20 UTC, as aud_event has
 * partitions for months around the build and none in 2099, where IssuesApiTokens' tokens are issued so the database
 * clock finds them live.
 */
final class ReportWhileInvestigatingPromptTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string PROMPT_FILE = __DIR__.'/../../../../src/AgentApi/Infrastructure/Prompt/report_while_investigating.v1.md';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    /** Words of the prompt's text that no audit row may hold. */
    private const array PROMPT_PHRASES = ['evidence log', 'find_tickets', 'create_report'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theServerOffersPromptsAndPromptsListShowsOnlyReportWhileInvestigatingWithNoArguments(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read, ApiTokenScope::RoundsWrite]);

        $this->postJsonRpc($token, self::initializeMessage());

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($this->responseValueAt('result', 'capabilities', 'prompts'), 'initialize does not offer prompts');
        $sessionId = $this->confirmSession($token);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'], $sessionId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([[
            'name' => ReportWhileInvestigatingPrompt::NAME,
            'title' => 'Report while you investigate',
            'description' => new ReportWhileInvestigatingPrompt()->definition()->description,
        ]], $this->responseValueAt('result', 'prompts'));
        self::assertSame([], $this->auditRowsOf($acmeFoods), 'listing is no read of the prompt');
    }

    /**
     * A token with rounds:write only, so a read that asked for the read scope, as the tools do, would be refused.
     */
    #[Test]
    public function promptsGetAnswersTheFilesTextUnchangedToAnyValidTokenAndWritesOneOkRowWithoutIt(): void
    {
        $promptText = file_get_contents(self::PROMPT_FILE);
        self::assertIsString($promptText);
        self::assertStringContainsString('find_tickets', $promptText, 'the test reads another file than the prompt');
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ReportWhileInvestigatingPrompt::NAME);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseValueAt('error'));
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $promptText]]], $this->responseValueAt('result', 'messages'));
        self::assertSame([$this->promptRow($omar, $tokenId, 'ok')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, ...self::PROMPT_PHRASES]);
    }

    #[Test]
    public function promptsListAndGetWithARealSessionAndNoTokenGet401AndWriteNoRow(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        foreach (['prompts/list' => [], 'prompts/get' => ['name' => ReportWhileInvestigatingPrompt::NAME]] as $method => $params) {
            $this->postJsonRpc(null, ['jsonrpc' => '2.0', 'id' => 3, 'method' => $method, 'params' => (object) $params], $sessionId);

            self::assertResponseStatusCodeSame(401, $method);
            self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson(), $method);
            self::assertStringNotContainsString('find_tickets', $this->client->getInternalResponse()->getContent(), $method);
        }
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function anUnknownPromptNameGetsAnInvalidParamsErrorAndNoRow(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, 'report_while_investigating.v1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_PARAMS, 'message' => GatedPromptGetHandler::UNKNOWN_PROMPT], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function argumentsGetAnInvalidParamsErrorWithoutThePromptAndOneInvalidInputRowWithoutThem(): void
    {
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ReportWhileInvestigatingPrompt::NAME, ['ticket' => 'SHOP-42']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_PARAMS, 'message' => GatedPromptGetHandler::TAKES_NO_ARGUMENTS], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([$this->promptRow($omar, $tokenId, 'invalid_input')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, 'SHOP-42', ...self::PROMPT_PHRASES]);
    }

    /**
     * @param list<ApiTokenScope> $scopes
     *
     * @return array{OrganizationId, string, string, User} Acme Foods, Omar's token id, its plain text and Omar
     */
    private function omarsTokenInAcmeFoods(array $scopes): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $acmeFoods, $omar->id(), 'Claude Code', $scopes, $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($acmeFoods, fn () => $this->apiTokens()->add($issued->token()));

        return [$acmeFoods, $issued->token()->id(), $issued->reveal(), $omar];
    }

    /**
     * @return string the Mcp-Session-Id
     */
    private function startSession(string $token): string
    {
        $this->postJsonRpc($token, self::initializeMessage());
        self::assertResponseStatusCodeSame(200);

        return $this->confirmSession($token);
    }

    /**
     * Sends notifications/initialized for the session the last initialize started.
     *
     * @return string the Mcp-Session-Id
     */
    private function confirmSession(string $token): string
    {
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        return $sessionId;
    }

    /**
     * @param ?array<string, string> $arguments left out of the request when null
     */
    private function getPrompt(string $token, string $sessionId, string $name, ?array $arguments = null): void
    {
        $params = null === $arguments ? ['name' => $name] : ['name' => $name, 'arguments' => $arguments];
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => $params], $sessionId);
    }

    /**
     * @return array{string, string, string, string, string, null, string, array<string, string>}
     */
    private function promptRow(User $user, string $tokenId, string $outcome): array
    {
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, ToolCallAuditor::PROMPT_TARGET_TYPE, null, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => ReportWhileInvestigatingPrompt::NAME]];
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

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(?string $token, array $message, ?string $sessionId = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => self::ACCEPT];
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = self::PROTOCOL_VERSION;
        }
        $this->client->request('POST', self::MCP, server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));
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
