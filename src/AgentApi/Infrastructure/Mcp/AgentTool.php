<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One MCP tool (ADR-0021): a thin adapter over another module's Api. ToolCallGate runs every call in this order: the
 * token scope, the input schema, targetOf(), the voter on that target, call(), then one audit row. AgentToolLoader
 * lists it in tools/list and GatedToolCallHandler answers its tools/call; nothing else runs it. Hold no state: the
 * same instance serves every request of a worker (ADR-0008).
 */
#[AutoconfigureTag(self::TAG)]
interface AgentTool
{
    public const string TAG = 'agent_api.tool';

    /**
     * What tools/list shows: a name unique among the tools, a description that marks which output fields are text
     * people entered (ADR-0020), and an input schema of type object, which every call is checked against.
     */
    public function definition(): Tool;

    /**
     * The token scope every call needs, such as "read" or "rounds:write"; a token without it gets a refusal.
     */
    public function requiredScope(): string;

    /**
     * Finds what the call acts on through a module Api, after the scope and input checks pass. Writes nothing.
     *
     * @param array<string, mixed> $arguments valid against the input schema
     *
     * @return ToolTarget|null null only from an OwnIdentityTool, which no voter guards; ToolCallGate fails the call of
     *                         any other tool that answers null
     *
     * @throws ToolRefusal not found when there is no such target
     */
    public function targetOf(array $arguments): ?ToolTarget;

    /**
     * Runs after the voter granted the target, in a savepoint of the request transaction: when it refuses or throws,
     * ToolCallGate rolls back what it wrote. It ends every transaction it begins and never ends the one it runs in. An
     * error result is never returned for a refusal.
     *
     * @param array<string, mixed> $arguments valid against the input schema
     * @param ToolTarget|null      $target    what targetOf() returned
     *
     * @throws ToolRefusal not found or read-only, from the module Api's domain refusal
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult;
}
