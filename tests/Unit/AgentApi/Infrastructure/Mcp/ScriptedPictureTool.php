<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\ScreenshotContextRequiredOverMcp;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * A ScreenshotContextRequiredOverMcp tool for the gate's tests, shaped like add_screenshot: it takes an upload with an
 * optional title and summary, which MCP requires, writes one label through the fixture when it runs, and counts how
 * often each step ran.
 */
final class ScriptedPictureTool implements ScreenshotContextRequiredOverMcp
{
    public const string NAME = 'scripted_picture_tool';
    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'upload_id' => ['type' => 'string', 'minLength' => 1],
            'title' => ['type' => 'string', 'maxLength' => 120],
            'summary' => ['type' => 'string', 'maxLength' => 600],
        ],
        'required' => ['upload_id'],
        'additionalProperties' => false,
    ];

    public int $targetLookups = 0;
    public int $calls = 0;

    public function __construct(private readonly GateFixture $fixture, private readonly ToolTarget $target)
    {
    }

    public function definition(): Tool
    {
        return new Tool(self::NAME, null, self::INPUT_SCHEMA, 'A picture tool of the tests.', null);
    }

    public function mcpDefinition(): Tool
    {
        return new Tool(self::NAME, null, ScreenshotContextArguments::requiredIn(self::INPUT_SCHEMA), 'A picture tool of the tests, over MCP.', null);
    }

    public function refuseMissingScreenshotContext(array $arguments): void
    {
        ScreenshotContextArguments::refuseMissingIn($arguments, '');
    }

    public function requiredScope(): string
    {
        return 'rounds:write';
    }

    public function targetOf(array $arguments): ToolTarget
    {
        ++$this->targetLookups;

        return $this->target;
    }

    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        ++$this->calls;
        $this->fixture->writeLabel('picture added');

        return new CallToolResult([new TextContent('added')]);
    }
}
