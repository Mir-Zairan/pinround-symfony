<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\AgentToolLoader;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AgentToolLoader on the SDK's own registry: tools/list shows each tool's definition as it is, and the handler the
 * registry holds for a tool only refuses, as nothing but GatedToolCallHandler may run a tool.
 */
final class AgentToolLoaderTest extends TestCase
{
    private const array WHOAMI_SCHEMA = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];

    #[Test]
    public function eachToolIsListedWithItsNameDescriptionAndInputSchema(): void
    {
        $ticketTool = new ScriptedTool('read', static fn (): ?ToolTarget => null);
        $whoAmILike = new ScriptedTool('read', static fn (): ?ToolTarget => null, name: 'whoami', inputSchema: self::WHOAMI_SCHEMA);
        $registry = new Registry();

        new AgentToolLoader([$ticketTool, $whoAmILike])->load($registry);

        $listed = array_map(static function (Tool $tool): array {
            $sent = json_decode(json_encode($tool, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($sent);

            return $sent;
        }, $registry->getTools()->references);
        self::assertSame([ScriptedTool::NAME, 'whoami'], array_keys($listed));
        self::assertSame(ScriptedTool::INPUT_SCHEMA, $listed[ScriptedTool::NAME]['inputSchema']);
        self::assertSame('A tool of the tests.', $listed[ScriptedTool::NAME]['description']);
        self::assertSame(['type' => 'object', 'properties' => [], 'additionalProperties' => false], $listed['whoami']['inputSchema']);
    }

    /**
     * tools/list is MCP's, so it shows the schema that requires a picture's title and summary (P14.T11).
     */
    #[Test]
    public function aPictureToolIsListedWithItsMcpDefinition(): void
    {
        $tool = new ScriptedPictureTool(new GateFixture(), new ToolTarget('ROUND_EDIT', 'a draft', 'round', 'a draft'));
        $registry = new Registry();

        new AgentToolLoader([$tool])->load($registry);

        $listed = $registry->getTool(ScriptedPictureTool::NAME)->tool;
        self::assertSame('A picture tool of the tests, over MCP.', $listed->description);
        self::assertSame(['upload_id', 'title', 'summary'], $listed->inputSchema['required'] ?? null);
    }

    #[Test]
    public function theRegistrysHandlerOfAToolOnlyRefuses(): void
    {
        $registry = new Registry();
        new AgentToolLoader([new ScriptedTool('read', static fn (): ?ToolTarget => null)])->load($registry);
        $handler = $registry->getTool(ScriptedTool::NAME)->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('An MCP tool ran without ToolCallGate');

        $handler();
    }

    #[Test]
    public function twoToolsOfOneNameAreRefused(): void
    {
        $registry = new Registry();
        $first = new ScriptedTool('read', static fn (): ?ToolTarget => null);
        $second = new ScriptedTool('rounds:write', static fn (): ?ToolTarget => null);

        try {
            new AgentToolLoader([$first, $second])->load($registry);
            self::fail('two tools of one name were loaded');
        } catch (\LogicException $refusal) {
            self::assertSame(\sprintf('Two MCP tools are named "%s".', ScriptedTool::NAME), $refusal->getMessage());
        }
    }
}
