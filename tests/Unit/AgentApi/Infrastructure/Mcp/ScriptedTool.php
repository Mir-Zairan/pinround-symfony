<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * An AgentTool for the gate's tests, shaped like a ticket tool: it takes a ticket key, finds its target through a
 * closure and counts how often each step ran.
 */
final class ScriptedTool implements AgentTool
{
    public const string NAME = 'scripted_ticket_tool';
    public const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => ['ticket' => ['type' => 'string', 'pattern' => '^[A-Z]+-[0-9]+$']],
        'required' => ['ticket'],
        'additionalProperties' => false,
    ];

    public int $targetLookups = 0;
    public int $calls = 0;

    /**
     * @param \Closure(array<string, mixed>): ?ToolTarget                      $findTarget
     * @param \Closure(array<string, mixed>, ?ToolTarget): CallToolResult|null $run         null answers "done"
     * @param array<string, mixed>                                             $inputSchema
     */
    public function __construct(
        private readonly string $scope,
        private readonly \Closure $findTarget,
        private readonly ?\Closure $run = null,
        private readonly string $name = self::NAME,
        private readonly array $inputSchema = self::INPUT_SCHEMA,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool($this->name, null, $this->inputSchema, 'A tool of the tests.', null);
    }

    public function requiredScope(): string
    {
        return $this->scope;
    }

    public function targetOf(array $arguments): ?ToolTarget
    {
        ++$this->targetLookups;

        return ($this->findTarget)($arguments);
    }

    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        ++$this->calls;

        return null === $this->run ? new CallToolResult([new TextContent('done')]) : ($this->run)($arguments, $target);
    }
}
