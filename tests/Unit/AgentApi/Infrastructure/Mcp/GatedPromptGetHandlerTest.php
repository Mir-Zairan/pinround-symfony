<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\GatedPromptGetHandler;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Prompt\AgentPrompt;
use App\AgentApi\Infrastructure\Prompt\ExplainMyNotebookPrompt;
use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GatedPromptGetHandler on doubles: each way a prompts/get ends, its audit row, that each name gets its own prompt, and
 * that a failure is logged by its class alone. tests/Functional/AgentApi/Prompt proves the same through POST /mcp.
 */
final class GatedPromptGetHandlerTest extends TestCase
{
    private const int REQUEST_ID = 7;

    private RecordingAuditLog $auditLog;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->auditLog = new RecordingAuditLog(new OrganizationId(GateFixture::ACME_FOODS));
        $this->logger = new CollectingLogger();
    }

    #[Test]
    public function itAnswersPromptsGetOnly(): void
    {
        $handler = $this->handler();

        self::assertTrue($handler->supports(self::getOf(ReportWhileInvestigatingPrompt::NAME)));
        self::assertFalse($handler->supports(ListPromptsRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'])));
    }

    #[Test]
    public function thePromptIsTheFilesTextAfterOneOkRow(): void
    {
        $answer = $this->handler()->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $answer);
        self::assertSame(self::REQUEST_ID, $answer->id);
        $sent = json_decode(json_encode($answer->result, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => new ReportWhileInvestigatingPrompt()->text()]]], $sent['messages'] ?? null);
        $this->assertOneRow('ok');
    }

    /**
     * The handler holds both prompts in each order, so answering the first or the last one listed is told apart.
     */
    #[Test]
    public function eachNameGetsItsOwnPromptsTextAndDescriptionAndARowWithItsName(): void
    {
        $prompts = [new ReportWhileInvestigatingPrompt(), new ExplainMyNotebookPrompt()];
        self::assertNotSame($prompts[0]->text(), $prompts[1]->text());

        foreach ([$prompts, array_reverse($prompts)] as $heldPrompts) {
            foreach ($prompts as $prompt) {
                $this->auditLog = new RecordingAuditLog(new OrganizationId(GateFixture::ACME_FOODS));
                $definition = $prompt->definition();

                $answer = $this->handler($heldPrompts)->handle(self::getOf($definition->name), self::createStub(SessionInterface::class));

                self::assertInstanceOf(Response::class, $answer);
                $sent = json_decode(json_encode($answer->result, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($sent);
                self::assertSame([['role' => 'user', 'content' => ['type' => 'text', 'text' => $prompt->text()]]], $sent['messages'] ?? null, $definition->name);
                self::assertSame($definition->description, $sent['description'] ?? null, $definition->name);
                $this->assertOneRow('ok', $definition->name);
            }
        }
    }

    #[Test]
    public function aNameInAnotherCaseIsUnknown(): void
    {
        $answer = $this->handler()->handle(self::getOf('Explain_My_Notebook'), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame([Error::INVALID_PARAMS, GatedPromptGetHandler::UNKNOWN_PROMPT], [$answer->code, $answer->message]);
        self::assertSame([], $this->auditLog->entries);
    }

    #[Test]
    public function anUnknownNameGetsAnInvalidParamsErrorAndNoRow(): void
    {
        $answer = $this->handler()->handle(self::getOf('delete_everything'), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame([self::REQUEST_ID, Error::INVALID_PARAMS, GatedPromptGetHandler::UNKNOWN_PROMPT], [$answer->id, $answer->code, $answer->message]);
        self::assertSame([], $this->auditLog->entries);
    }

    #[Test]
    public function argumentsGetAnInvalidParamsErrorAndOneInvalidInputRow(): void
    {
        $answer = $this->handler()->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME, ['ticket' => 'SHOP-42']), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame([Error::INVALID_PARAMS, GatedPromptGetHandler::TAKES_NO_ARGUMENTS], [$answer->code, $answer->message]);
        $this->assertOneRow('invalid_input');
    }

    #[Test]
    public function anEmptyArgumentObjectIsNoArgument(): void
    {
        $answer = $this->handler()->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME, []), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $answer);
        $this->assertOneRow('ok');
    }

    #[Test]
    public function aPromptFileThatCannotBeReadGetsTheGenericErrorOneErrorRowAndALogLineWithTheClassOnly(): void
    {
        $handler = $this->handler([new ReportWhileInvestigatingPrompt(__DIR__.'/no-such-folder'), new ExplainMyNotebookPrompt()]);

        $answer = $handler->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame([self::REQUEST_ID, Error::INTERNAL_ERROR, GatedPromptGetHandler::PROMPT_FAILED], [$answer->id, $answer->code, $answer->message]);
        $this->assertOneRow('error');
        self::assertSame([['level' => 'error', 'message' => 'An MCP prompt could not be read.', 'context' => ['exception_class' => \RuntimeException::class]]], $this->logger->records);
    }

    #[Test]
    public function anAuditRowThatCannotBeWrittenKeepsThePromptFromTheClient(): void
    {
        $failingAuditLog = new RecordingAuditLog(new OrganizationId(GateFixture::ACME_FOODS), new \RuntimeException('the audit table is gone'));
        $handler = new GatedPromptGetHandler([new ReportWhileInvestigatingPrompt()], self::omarsToken(true), self::omar(true), new ToolCallAuditor($failingAuditLog), $this->logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('the audit table is gone');

        $handler->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME), self::createStub(SessionInterface::class));
    }

    #[Test]
    public function aRequestWithoutATokenIsALogicErrorWithNoRow(): void
    {
        $handler = new GatedPromptGetHandler([new ReportWhileInvestigatingPrompt()], self::omarsToken(false), self::omar(true), new ToolCallAuditor($this->auditLog), $this->logger);

        try {
            $handler->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME), self::createStub(SessionInterface::class));
            self::fail('a request without a token was answered');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('without a personal access token', $refusal->getMessage());
        }
        self::assertSame([], $this->auditLog->entries);
    }

    #[Test]
    public function aRequestWithoutAUserIsALogicErrorWithNoRow(): void
    {
        $handler = new GatedPromptGetHandler([new ReportWhileInvestigatingPrompt()], self::omarsToken(true), self::omar(false), new ToolCallAuditor($this->auditLog), $this->logger);

        try {
            $handler->handle(self::getOf(ReportWhileInvestigatingPrompt::NAME), self::createStub(SessionInterface::class));
            self::fail('a request without a user was answered');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('without a user', $refusal->getMessage());
        }
        self::assertSame([], $this->auditLog->entries);
    }

    /**
     * @param ?list<AgentPrompt> $prompts both prompts when null
     */
    private function handler(?array $prompts = null): GatedPromptGetHandler
    {
        return new GatedPromptGetHandler($prompts ?? [new ReportWhileInvestigatingPrompt(), new ExplainMyNotebookPrompt()], self::omarsToken(true), self::omar(true), new ToolCallAuditor($this->auditLog), $this->logger);
    }

    private function assertOneRow(string $outcome, string $promptName = ReportWhileInvestigatingPrompt::NAME): void
    {
        self::assertCount(1, $this->auditLog->entries);
        $entry = $this->auditLog->entries[0];
        self::assertSame([ToolCallAuditor::ACTION, ToolCallAuditor::PROMPT_TARGET_TYPE, null], [$entry->action, $entry->targetType, $entry->targetId]);
        self::assertSame(['tool' => $promptName, 'outcome' => $outcome], $entry->data);
    }

    /**
     * @param ?array<string, string> $arguments left out of the request when null
     */
    private static function getOf(string $name, ?array $arguments = null): GetPromptRequest
    {
        $params = null === $arguments ? ['name' => $name] : ['name' => $name, 'arguments' => $arguments];

        return GetPromptRequest::fromArray(['jsonrpc' => '2.0', 'id' => self::REQUEST_ID, 'method' => 'prompts/get', 'params' => $params]);
    }

    private static function omarsToken(bool $isPresent): CurrentApiToken
    {
        $access = $isPresent ? new ApiTokenAccess(GateFixture::OMARS_TOKEN, new OrganizationId(GateFixture::ACME_FOODS), ['rounds:write'], new \DateTimeImmutable('2027-01-31 09:30:00 UTC')) : null;

        return new readonly class($access) implements CurrentApiToken {
            public function __construct(private ?ApiTokenAccess $access)
            {
            }

            public function access(): ?ApiTokenAccess
            {
                return $this->access;
            }
        };
    }

    private static function omar(bool $isPresent): CurrentUser
    {
        return new readonly class($isPresent ? new UserId(GateFixture::OMAR) : null) implements CurrentUser {
            public function __construct(private ?UserId $userId)
            {
            }

            public function id(): ?UserId
            {
                return $this->userId;
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                return null;
            }
        };
    }
}
