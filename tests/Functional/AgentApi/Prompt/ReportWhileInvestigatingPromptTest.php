<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Prompt;

use App\AgentApi\Infrastructure\Mcp\GatedPromptGetHandler;
use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
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
    use CallsMcpPrompts;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-10 09:00:20 UTC';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
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
        $this->removeAuditRows();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theServerOffersPromptsAndPromptsListShowsReportWhileInvestigatingWithNoArguments(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read, ApiTokenScope::RoundsWrite]);

        $this->postJsonRpc($token, self::initializeMessage());

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($this->responseValueAt('result', 'capabilities', 'prompts'), 'initialize does not offer prompts');
        $sessionId = $this->confirmSession($token);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'], $sessionId);

        self::assertResponseStatusCodeSame(200);
        $listedPrompts = $this->responseValueAt('result', 'prompts');
        self::assertIsArray($listedPrompts);
        self::assertContains([
            'name' => ReportWhileInvestigatingPrompt::NAME,
            'title' => 'Report while you investigate',
            'description' => new ReportWhileInvestigatingPrompt()->definition()->description,
        ], $listedPrompts);
        self::assertSame([], $this->auditRowsOf($acmeFoods), 'listing is no read of the prompt');
    }

    /**
     * A token with rounds:write only, so a read that asked for the read scope, as the tools do, would be refused.
     */
    #[Test]
    public function promptsGetAnswersTheFilesTextUnchangedToAnyValidTokenAndWritesOneOkRowWithoutIt(): void
    {
        $promptText = self::promptFileText(ReportWhileInvestigatingPrompt::VERSION);
        self::assertStringContainsString('find_tickets', $promptText, 'the test reads another file than the prompt');
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ReportWhileInvestigatingPrompt::NAME);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseValueAt('error'));
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $promptText]]], $this->responseValueAt('result', 'messages'));
        self::assertSame([$this->promptRow($omar, $tokenId, ReportWhileInvestigatingPrompt::NAME, 'ok')], $this->auditRowsOf($acmeFoods));
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
        self::assertSame([$this->promptRow($omar, $tokenId, ReportWhileInvestigatingPrompt::NAME, 'invalid_input')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, 'SHOP-42', ...self::PROMPT_PHRASES]);
    }
}
