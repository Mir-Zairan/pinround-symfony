<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Prompt;

use App\AgentApi\Infrastructure\Mcp\GatedPromptGetHandler;
use App\AgentApi\Infrastructure\Prompt\ExplainMyNotebookPrompt;
use App\AgentApi\Infrastructure\Prompt\FixMyRoundNotesPrompt;
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
 * The MCP prompt explain_my_notebook through POST /mcp (P13.T9, ADR-0046): listed beside report_while_investigating,
 * answered with its own file behind the same api_token firewall, limits and session binding as the tools, and one
 * audit row per read with the prompt's name and the outcome only. The clock is frozen at 2026-11-10 09:00:20 UTC, as
 * aud_event has partitions for months around the build and none in 2099, where IssuesApiTokens' tokens are issued.
 */
final class ExplainMyNotebookPromptTest extends WebTestCase
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
    /** Words of the prompt's text that no audit row and no refusal may hold. */
    private const array PROMPT_PHRASES = ['Answer pin by pin', 'save_scenarios', 'untrusted_text'];

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
    public function promptsListShowsBothPromptsEachWithItsTitleAndDescriptionAndNoArguments(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'], $sessionId);

        self::assertResponseStatusCodeSame(200);
        $listedPrompts = $this->responseValueAt('result', 'prompts');
        self::assertIsArray($listedPrompts);
        usort($listedPrompts, static fn (mixed $first, mixed $second): int => (\is_array($first) ? $first['name'] ?? '' : '') <=> (\is_array($second) ? $second['name'] ?? '' : ''));
        self::assertSame([
            [
                'name' => ExplainMyNotebookPrompt::NAME,
                'title' => 'Explain my notebook',
                'description' => new ExplainMyNotebookPrompt()->definition()->description,
            ],
            [
                'name' => FixMyRoundNotesPrompt::NAME,
                'title' => 'Fix my round notes',
                'description' => FixMyRoundNotesPrompt::DESCRIPTION,
                'arguments' => [['name' => FixMyRoundNotesPrompt::KEY_ARGUMENT, 'description' => 'The ticket key, for example SHOP-12.', 'required' => true]],
            ],
            [
                'name' => ReportWhileInvestigatingPrompt::NAME,
                'title' => 'Report while you investigate',
                'description' => new ReportWhileInvestigatingPrompt()->definition()->description,
            ],
        ], $listedPrompts);
        self::assertSame([], $this->auditRowsOf($acmeFoods), 'listing is no read of a prompt');
    }

    /**
     * A token with rounds:write only, so a read that asked for the read or notebooks:write scope would be refused.
     */
    #[Test]
    public function promptsGetAnswersTheFilesTextUnchangedToAnyValidTokenAndWritesOneOkRowWithItsNameOnly(): void
    {
        $promptText = self::promptFileText(ExplainMyNotebookPrompt::VERSION);
        self::assertStringContainsString('list_my_notebooks', $promptText, 'the test reads another file than the prompt');
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ExplainMyNotebookPrompt::NAME);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseValueAt('error'));
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $promptText]]], $this->responseValueAt('result', 'messages'));
        self::assertSame(new ExplainMyNotebookPrompt()->definition()->description, $this->responseValueAt('result', 'description'));
        self::assertSame([$this->promptRow($omar, $tokenId, ExplainMyNotebookPrompt::NAME, 'ok')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, ...self::PROMPT_PHRASES]);
    }

    #[Test]
    public function eachPromptInOneSessionAnswersItsOwnFileAndWritesARowWithItsOwnName(): void
    {
        $explainText = self::promptFileText(ExplainMyNotebookPrompt::VERSION);
        $reportText = self::promptFileText(ReportWhileInvestigatingPrompt::VERSION);
        self::assertNotSame($explainText, $reportText);
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ReportWhileInvestigatingPrompt::NAME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $reportText]]], $this->responseValueAt('result', 'messages'));

        $this->getPrompt($token, $sessionId, ExplainMyNotebookPrompt::NAME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $explainText]]], $this->responseValueAt('result', 'messages'));
        $rows = $this->auditRowsOf($acmeFoods);
        usort($rows, static fn (array $first, array $second): int => json_encode($first[7], \JSON_THROW_ON_ERROR) <=> json_encode($second[7], \JSON_THROW_ON_ERROR));
        self::assertSame([
            $this->promptRow($omar, $tokenId, ExplainMyNotebookPrompt::NAME, 'ok'),
            $this->promptRow($omar, $tokenId, ReportWhileInvestigatingPrompt::NAME, 'ok'),
        ], $rows);
    }

    #[Test]
    public function promptsGetWithARealSessionAndNoTokenGets401WithoutThePromptAndWritesNoRow(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->postJsonRpc(null, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => ['name' => ExplainMyNotebookPrompt::NAME]], $sessionId);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], $this->responseJson());
        foreach (self::PROMPT_PHRASES as $promptPhrase) {
            self::assertStringNotContainsString($promptPhrase, $this->client->getInternalResponse()->getContent());
        }
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function aNameNextToAPromptsNameIsStillUnknownAndWritesNoRow(): void
    {
        [$acmeFoods, , $token] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        foreach (['explain_my_notebook.v1', 'Explain_My_Notebook', 'explain_my_notebooks'] as $unknownName) {
            $this->getPrompt($token, $sessionId, $unknownName);

            self::assertResponseStatusCodeSame(200, $unknownName);
            self::assertSame(['code' => self::INVALID_PARAMS, 'message' => GatedPromptGetHandler::UNKNOWN_PROMPT], $this->responseValueAt('error'), $unknownName);
            self::assertNull($this->responseValueAt('result'), $unknownName);
        }
        self::assertSame([], $this->auditRowsOf($acmeFoods));
    }

    #[Test]
    public function argumentsGetAnInvalidParamsErrorWithoutThePromptAndOneInvalidInputRowWithItsName(): void
    {
        [$acmeFoods, $tokenId, $token, $omar] = $this->omarsTokenInAcmeFoods([ApiTokenScope::Read]);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, ExplainMyNotebookPrompt::NAME, ['notebook' => 'Checkout at Acme Foods']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_PARAMS, 'message' => GatedPromptGetHandler::TAKES_NO_ARGUMENTS], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        self::assertSame([$this->promptRow($omar, $tokenId, ExplainMyNotebookPrompt::NAME, 'invalid_input')], $this->auditRowsOf($acmeFoods));
        $this->assertNoAuditRowHolds($acmeFoods, [$token, 'Checkout at Acme Foods', ...self::PROMPT_PHRASES]);
    }
}
