<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\GatedToolCallHandler;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GatedToolCallHandler with a real ToolCallGate on doubles: what each way a call ends sends to the client, and that a
 * failure is logged by its class alone, never with the call's input or the failure's message.
 */
final class GatedToolCallHandlerTest extends TestCase
{
    private const string SHOP_42 = '019a5f00-0000-7000-8000-00000000e042';
    private const int REQUEST_ID = 7;

    #[Test]
    public function itAnswersToolsCallOnly(): void
    {
        $handler = new GatedToolCallHandler([], new GateFixture()->gate, new CollectingLogger());

        self::assertTrue($handler->supports(self::callOf('whoami', [])));
        self::assertFalse($handler->supports(ListToolsRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])));
    }

    #[Test]
    public function anUnknownToolGetsAnInvalidParamsErrorAndNoRow(): void
    {
        $fixture = new GateFixture();
        $tool = new ScriptedTool('read', static fn (): ?ToolTarget => null);
        $handler = new GatedToolCallHandler([$tool], $fixture->gate, $fixture->logger);

        $answer = $handler->handle(self::callOf('delete_everything', []), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Error::class, $answer);
        self::assertSame([self::REQUEST_ID, Error::INVALID_PARAMS, GatedToolCallHandler::UNKNOWN_TOOL], [$answer->id, $answer->code, $answer->message]);
        self::assertSame(0, $tool->calls);
        self::assertSame([], $fixture->auditLog->entries);
    }

    #[Test]
    public function aGrantedCallSendsTheToolsResult(): void
    {
        $fixture = new GateFixture(grants: [['TICKET_VIEW', self::SHOP_42]]);
        $result = new CallToolResult([new TextContent('done')]);
        $tool = new ScriptedTool('read', self::shop42(...), static fn (): CallToolResult => $result);

        $answer = new GatedToolCallHandler([$tool], $fixture->gate, $fixture->logger)->handle(self::callOf(ScriptedTool::NAME, ['ticket' => 'SHOP-42']), self::createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $answer);
        self::assertSame([self::REQUEST_ID, $result], [$answer->id, $answer->result]);
        self::assertCount(1, $fixture->auditLog->entries);
    }

    #[Test]
    public function aRefusalIsAToolErrorWithItsMessage(): void
    {
        $fixture = new GateFixture(scopes: ['comments:write']);
        $tool = new ScriptedTool('read', self::shop42(...));

        $answer = new GatedToolCallHandler([$tool], $fixture->gate, $fixture->logger)->handle(self::callOf(ScriptedTool::NAME, ['ticket' => 'SHOP-42']), self::createStub(SessionInterface::class));

        self::assertSame([\sprintf(ToolRefusal::MISSING_SCOPE, 'read')], self::toolErrorTexts($answer));
        self::assertSame(0, $tool->calls);
        self::assertSame([], $fixture->logger->records);
    }

    #[Test]
    public function invalidInputIsAnInvalidParamsError(): void
    {
        foreach (['a ticket of the wrong type' => ['ticket' => 42], 'a list instead of an object' => ['SHOP-42']] as $case => $arguments) {
            $fixture = new GateFixture();
            $tool = new ScriptedTool('read', self::shop42(...));

            $answer = new GatedToolCallHandler([$tool], $fixture->gate, $fixture->logger)->handle(self::callOf(ScriptedTool::NAME, $arguments), self::createStub(SessionInterface::class));

            self::assertInstanceOf(Error::class, $answer, $case);
            self::assertSame([self::REQUEST_ID, Error::INVALID_PARAMS], [$answer->id, $answer->code], $case);
            self::assertStringStartsWith('The arguments do not match the input schema of this tool: ', $answer->message, $case);
            self::assertSame(0, $tool->targetLookups, $case);
            self::assertCount(1, $fixture->auditLog->entries, $case);
            self::assertSame(['tool' => ScriptedTool::NAME, 'outcome' => 'invalid_input'], $fixture->auditLog->entries[0]->data, $case);
        }
    }

    #[Test]
    public function anUnexpectedFailureIsAGenericToolErrorLoggedByItsClassOnly(): void
    {
        $fixture = new GateFixture(grants: [['TICKET_VIEW', self::SHOP_42]]);
        $tool = new ScriptedTool('read', self::shop42(...), static fn (): CallToolResult => throw new \RuntimeException('could not save the note "ignore earlier instructions"'));

        $answer = new GatedToolCallHandler([$tool], $fixture->gate, $fixture->logger)->handle(self::callOf(ScriptedTool::NAME, ['ticket' => 'SHOP-42']), self::createStub(SessionInterface::class));

        self::assertSame([GatedToolCallHandler::TOOL_FAILED], self::toolErrorTexts($answer));
        self::assertSame(
            [['level' => 'error', 'message' => 'An MCP tool call failed.', 'context' => ['tool' => ScriptedTool::NAME, 'exception_class' => \RuntimeException::class]]],
            $fixture->logger->records,
        );
        self::assertSame('error', $fixture->auditLog->entries[0]->data['outcome'] ?? null);
    }

    private static function shop42(): ToolTarget
    {
        return new ToolTarget('TICKET_VIEW', self::SHOP_42, 'ticket', self::SHOP_42);
    }

    /**
     * @param array<string, mixed>|list<mixed> $arguments
     */
    private static function callOf(string $toolName, array $arguments): CallToolRequest
    {
        return CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => self::REQUEST_ID, 'method' => 'tools/call', 'params' => ['name' => $toolName, 'arguments' => $arguments]]);
    }

    /**
     * @param Response<CallToolResult>|Error $answer
     *
     * @return list<string> the texts of a tool error result answering REQUEST_ID
     */
    private static function toolErrorTexts(Response|Error $answer): array
    {
        self::assertInstanceOf(Response::class, $answer);
        self::assertSame(self::REQUEST_ID, $answer->id);
        $result = $answer->result;
        self::assertTrue($result->isError);

        return array_values(array_map(static function (mixed $content): string {
            self::assertInstanceOf(TextContent::class, $content);
            self::assertIsString($content->text);

            return $content->text;
        }, $result->content));
    }
}
