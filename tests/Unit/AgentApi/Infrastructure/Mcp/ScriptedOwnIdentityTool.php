<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\OwnIdentityTool;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * An OwnIdentityTool for the gate's tests, shaped like whoami: no target, and it counts how often it ran.
 */
final class ScriptedOwnIdentityTool implements OwnIdentityTool
{
    public int $calls = 0;

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(private readonly array $inputSchema = ScriptedTool::INPUT_SCHEMA)
    {
    }

    public function definition(): Tool
    {
        return new Tool(ScriptedTool::NAME, null, $this->inputSchema, 'A tool of the tests.', null);
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    public function targetOf(array $arguments): ?ToolTarget
    {
        return null;
    }

    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        ++$this->calls;

        return new CallToolResult([new TextContent('done')]);
    }
}
