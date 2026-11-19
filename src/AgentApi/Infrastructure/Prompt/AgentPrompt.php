<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

use Mcp\Schema\Prompt;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One MCP prompt: a versioned file of instructions for a client, holding no data of the organization. AgentPromptLoader
 * lists it in prompts/list and GatedPromptGetHandler answers its prompts/get; nothing else reads it. Hold no state: the
 * same instance serves every request of a worker (ADR-0008).
 */
#[AutoconfigureTag(self::TAG)]
interface AgentPrompt
{
    public const string TAG = 'agent_api.prompt';

    /**
     * What prompts/list shows: a name unique among the prompts, and no arguments, as GatedPromptGetHandler refuses them.
     */
    public function definition(): Prompt;

    /**
     * @return string the prompt file's text, byte for byte
     *
     * @throws \RuntimeException when the file cannot be read, so the prompt is refused rather than answered empty
     */
    public function text(): string;
}
