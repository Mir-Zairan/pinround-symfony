<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use Mcp\Schema\Prompt;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One MCP prompt: a versioned file of instructions for a client, holding no data of the organization; a prompt that
 * takes arguments fills them into its file and nothing else. AgentPromptLoader lists it in prompts/list and
 * GatedPromptGetHandler answers its prompts/get; nothing else reads it. Hold no state: the same instance serves every
 * request of a worker (ADR-0008).
 */
#[AutoconfigureTag(self::TAG)]
interface AgentPrompt
{
    public const string TAG = 'agent_api.prompt';

    /**
     * What prompts/list shows: a name unique among the prompts, and the arguments it takes, if any. GatedPromptGetHandler
     * refuses any argument it does not list, and a listed required one that is missing.
     */
    public function definition(): Prompt;

    /**
     * @param array<string, string> $arguments only names definition() lists, each with text, every required one
     *                                         there; empty for a prompt that lists none
     *
     * @return string the prompt file's text, byte for byte, with the arguments filled in
     *
     * @throws ToolRefusal       when an argument is refused, such as a malformed value or a target the caller may not
     *                           see; its message goes to the client
     * @throws \RuntimeException when the file cannot be read, so the prompt is refused rather than answered empty
     */
    public function text(array $arguments): string;
}
