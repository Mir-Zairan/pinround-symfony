<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Prompt\AgentPrompt;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;

/**
 * An AgentPrompt that takes arguments, for the handler's tests, shaped like fix_my_round_notes: a required "key" and an
 * optional "round". It keeps the arguments of each text() call and refuses SHOP-99 as not found, as a prompt refuses a
 * ticket the caller may not see.
 */
final class ScriptedPrompt implements AgentPrompt
{
    public const string NAME = 'scripted_ticket_prompt';
    public const string UNKNOWN_KEY = 'SHOP-99';

    /** @var list<array<string, string>> */
    public array $renderedArguments = [];

    public function definition(): Prompt
    {
        return new Prompt(
            name: self::NAME,
            description: 'A prompt of the tests.',
            arguments: [new PromptArgument('key', 'The ticket key.', true), new PromptArgument('round', 'The round number.', false)],
        );
    }

    public function text(array $arguments): string
    {
        $this->renderedArguments[] = $arguments;
        if (self::UNKNOWN_KEY === ($arguments['key'] ?? null)) {
            throw ToolRefusal::notFound();
        }
        ksort($arguments);

        return 'Fix '.implode(' in round ', $arguments).'.';
    }
}
