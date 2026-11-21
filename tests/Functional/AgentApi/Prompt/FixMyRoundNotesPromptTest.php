<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Prompt;

use App\AgentApi\Infrastructure\Mcp\GatedPromptGetHandler;
use App\AgentApi\Infrastructure\Mcp\McpServerInstructions;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Prompt\FixMyRoundNotesPrompt;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
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
 * The server instructions and the MCP prompt fix_my_round_notes through POST /mcp (P14.T12, FR-MCP-15, FR-MCP-16): the
 * capture rule in every initialize result, the prompt listed with its key argument, and prompts/get rendering only for
 * a ticket the caller may read get_round_notes on. SHOP-1 is Acme Foods' Shop ticket, Omar's report assigned to him;
 * APP-1 is a Mobile ticket for that team only; Beta Bakery has its own SHOP-1. The clock is frozen at 2026-11-10
 * 09:00:20 UTC, a day an aud_event partition exists for and not today.
 */
final class FixMyRoundNotesPromptTest extends WebTestCase
{
    use CallsMcpPrompts;
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-10 09:00:20 UTC';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    /** Words of the prompt's text that no audit row and no refusal may hold. */
    private const array PROMPT_PHRASES = ['get_round_notes', 'list_draft_screenshots', 'evidence log'];
    /** The title of every seeded ticket, which no answer may hold. */
    private const string TICKET_TITLE = 'Cart total shows the wrong sum';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = $this->addTodoStep($this->acmeFoods);
    }

    protected function tearDown(): void
    {
        $this->removeAuditRows();
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function initializeCarriesTheCaptureRuleAndOffersPrompts(): void
    {
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->postJsonRpc($token, self::initializeMessage());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(McpServerInstructions::TEXT, $this->responseValueAt('result', 'instructions'));
        foreach (['save it at that moment as a file in a local evidence log', 'with its title', 'its summary', 'its pins', 'never at the end', 'Upload nothing to pinround until the person asks you to.', 'retake_of'] as $captureRule) {
            self::assertStringContainsString($captureRule, McpServerInstructions::TEXT);
        }
        self::assertIsArray($this->responseValueAt('result', 'capabilities', 'prompts'), 'initialize does not offer prompts');
    }

    #[Test]
    public function promptsListShowsFixMyRoundNotesWithItsRequiredKeyArgument(): void
    {
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'], $sessionId);

        self::assertResponseStatusCodeSame(200);
        $listedPrompts = $this->responseValueAt('result', 'prompts');
        self::assertIsArray($listedPrompts);
        $promptsByName = array_column($listedPrompts, null, 'name');
        self::assertSame([
            'name' => FixMyRoundNotesPrompt::NAME,
            'title' => 'Fix my round notes',
            'description' => FixMyRoundNotesPrompt::DESCRIPTION,
            'arguments' => [['name' => 'key', 'description' => 'The ticket key, for example SHOP-12.', 'required' => true]],
        ], $promptsByName[FixMyRoundNotesPrompt::NAME] ?? null);
        self::assertSame([], $this->auditRowsOf($this->acmeFoods), 'listing is no read of a prompt');
    }

    #[Test]
    public function promptsGetWithAKeyOfAVisibleTicketRendersTheFileWithTheKeyAndWritesOneOkRowWithoutIt(): void
    {
        $this->addTicketOf($this->acmeFoods, $this->shopProject, $this->shop->toString(), 'SHOP-1', 'all', $this->stepId, $this->people['Omar']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, FixMyRoundNotesPrompt::NAME, ['key' => 'SHOP-1']);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseValueAt('error'));
        $expectedText = str_replace('{{key}}', 'SHOP-1', self::promptFileText(FixMyRoundNotesPrompt::VERSION));
        self::assertStringContainsString('Call `get_round_notes` with the key `SHOP-1`.', $expectedText, 'the test reads another file than the prompt');
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $expectedText]]], $this->responseValueAt('result', 'messages'));
        self::assertStringNotContainsString(self::TICKET_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'ok')], $this->auditRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds($this->acmeFoods, [$token, 'SHOP-1', ...self::PROMPT_PHRASES]);
    }

    #[Test]
    public function aMissingKeyGetsAnInvalidParamsErrorWithoutThePromptAndOneInvalidInputRow(): void
    {
        $this->addTicketOf($this->acmeFoods, $this->shopProject, $this->shop->toString(), 'SHOP-1', 'all', $this->stepId, $this->people['Omar']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, FixMyRoundNotesPrompt::NAME);

        $this->assertRefusedWith(\sprintf(ToolRefusal::INVALID_PROMPT_ARGUMENTS, \sprintf(GatedPromptGetHandler::IS_REQUIRED, 'key')));
        self::assertSame([$this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'invalid_input')], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * A key with a line break after it would pass a pattern anchored with a PCRE $ that is not the true end; an argument
     * the prompt does not list and a key that is not text are refused too.
     */
    #[Test]
    public function aMalformedKeyOrAnUnlistedArgumentGetsAnInvalidParamsErrorAndOneInvalidInputRowEach(): void
    {
        $this->addTicketOf($this->acmeFoods, $this->shopProject, $this->shop->toString(), 'SHOP-1', 'all', $this->stepId, $this->people['Omar']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);
        $malformedKey = \sprintf(ToolRefusal::INVALID_PROMPT_ARGUMENTS, FixMyRoundNotesPrompt::MALFORMED_KEY);
        $refusals = [
            [['key' => "SHOP-1\n"], $malformedKey],
            [['key' => 'shop-1'], $malformedKey],
            [['key' => 'SHOP-1 OR 1=1'], $malformedKey],
            [['key' => ''], $malformedKey],
            [['key' => 'SHOP-1', 'round' => '2'], \sprintf(ToolRefusal::INVALID_PROMPT_ARGUMENTS, \sprintf(GatedPromptGetHandler::TAKES_ONLY, 'key'))],
            [['key' => 1], \sprintf(ToolRefusal::INVALID_PROMPT_ARGUMENTS, \sprintf(GatedPromptGetHandler::MUST_BE_TEXT, 'key'))],
        ];

        foreach ($refusals as [$arguments, $message]) {
            $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => ['name' => FixMyRoundNotesPrompt::NAME, 'arguments' => $arguments]], $sessionId);

            $this->assertRefusedWith($message);
        }
        $invalidInputRow = $this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'invalid_input');
        self::assertSame(array_fill(0, \count($refusals), $invalidInputRow), $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * An unknown key and a Mobile ticket for that team only, which Omar may not see, get the same refusal, so the answer
     * does not tell whether the ticket exists.
     */
    #[Test]
    public function anUnknownKeyAndATicketTheCallerMayNotSeeAreNotFoundAlike(): void
    {
        $appProject = $this->addProject($this->mobile, 'APP', 'App');
        $this->addTicketOf($this->acmeFoods, $appProject, $this->mobile->toString(), 'APP-1', 'team', $this->stepId, $this->people['Lena']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);

        foreach (['APP-1', 'SHOP-9'] as $key) {
            $this->getPrompt($token, $sessionId, FixMyRoundNotesPrompt::NAME, ['key' => $key]);

            $this->assertRefusedWith(ToolRefusal::NOT_FOUND);
        }
        $notFoundRow = $this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'not_found');
        self::assertSame([$notFoundRow, $notFoundRow], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * Beta Bakery has its own SHOP-1 and Omar is its member too, but his token is Acme Foods', which has no SHOP-1. His
     * Beta Bakery token renders it, so the refusal is the organization's and not the key's.
     */
    #[Test]
    public function aTicketOfAnotherOrganizationIsNotFoundThoughItHasTheSameKey(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $betaBakery);
        $bakeryShop = $this->addProject(null, 'SHOP', 'Bakery shop', $betaBakery);
        $this->addTicketOf($betaBakery, $bakeryShop, null, 'SHOP-1', 'all', $this->addTodoStep($betaBakery), $this->people['Omar']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->getPrompt($token, $sessionId, FixMyRoundNotesPrompt::NAME, ['key' => 'SHOP-1']);

        $this->assertRefusedWith(ToolRefusal::NOT_FOUND);
        self::assertSame([$this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'not_found')], $this->auditRowsOf($this->acmeFoods));
        self::assertSame([], $this->auditRowsOf($betaBakery));

        [$bakeryTokenId, $bakeryToken] = $this->issueApiToken($this->people['Omar'], $betaBakery);
        $bakerySessionId = $this->startSession($bakeryToken);
        $this->getPrompt($bakeryToken, $bakerySessionId, FixMyRoundNotesPrompt::NAME, ['key' => 'SHOP-1']);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->responseValueAt('error'));
        self::assertSame(str_replace('{{key}}', 'SHOP-1', self::promptFileText(FixMyRoundNotesPrompt::VERSION)), $this->responseValueAt('result', 'messages', '0', 'content', 'text'));
        self::assertSame([$this->promptRow($this->people['Omar'], $bakeryTokenId, FixMyRoundNotesPrompt::NAME, 'ok')], $this->auditRowsOf($betaBakery));
    }

    /**
     * The prompt has the client call get_round_notes first, which needs the read scope; a token without it gets no
     * answer about the key, not even whether it is well formed.
     */
    #[Test]
    public function aTokenWithoutTheReadScopeGetsMissingScopeAndNoPrompt(): void
    {
        $this->addTicketOf($this->acmeFoods, $this->shopProject, $this->shop->toString(), 'SHOP-1', 'all', $this->stepId, $this->people['Omar']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods, scopes: [ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        foreach (['SHOP-1', 'shop-1'] as $key) {
            $this->getPrompt($token, $sessionId, FixMyRoundNotesPrompt::NAME, ['key' => $key]);

            $this->assertRefusedWith(\sprintf(ToolRefusal::MISSING_SCOPE, 'read'));
        }
        $missingScopeRow = $this->promptRow($this->people['Omar'], $tokenId, FixMyRoundNotesPrompt::NAME, 'missing_scope');
        self::assertSame([$missingScopeRow, $missingScopeRow], $this->auditRowsOf($this->acmeFoods));
    }

    private function assertRefusedWith(string $message): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['code' => self::INVALID_PARAMS, 'message' => $message], $this->responseValueAt('error'));
        self::assertNull($this->responseValueAt('result'));
        $body = $this->client->getInternalResponse()->getContent();
        foreach ([self::TICKET_TITLE, ...self::PROMPT_PHRASES] as $phrase) {
            self::assertStringNotContainsString($phrase, $body);
        }
    }

    private function addTodoStep(OrganizationId $organizationId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId->toString(), $stepId],
        ));

        return $stepId;
    }

    /**
     * A ticket that $person reported and is assigned, unlike SeedsTicketRounds::addTicket, which is always Acme Foods'
     * Shop ticket.
     *
     * @return string the ticket's id
     */
    private function addTicketOf(OrganizationId $organizationId, string $projectId, ?string $teamId, string $key, string $visibility, string $stepId, User $person): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [$organizationId->toString(), $ticketId, $projectId, $teamId, (int) substr($key, (int) strpos($key, '-') + 1), $key, self::TICKET_TITLE, $stepId, $person->id()->toString(), $person->id()->toString(), $visibility];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, ?, 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $ticketId;
    }
}
