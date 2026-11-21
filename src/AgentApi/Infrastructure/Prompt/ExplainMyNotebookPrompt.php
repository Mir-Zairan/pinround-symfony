<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

use Mcp\Schema\Prompt;

/**
 * The MCP prompt explain_my_notebook (P13.T9, ADR-0046): how a client reads one of the person's notebooks, answers it
 * pin by pin, and, when asked for cases, writes scenarios and saves them with save_scenarios. Its text is the versioned
 * file VERSION names, beside this class. Holds no state (ADR-0008): the file is read on each call.
 */
final readonly class ExplainMyNotebookPrompt implements AgentPrompt
{
    public const string NAME = 'explain_my_notebook';
    public const string VERSION = 'explain_my_notebook.v1';

    /**
     * @param string $promptDirectory where the versioned files are: this class's own folder; a test may name another
     */
    public function __construct(private string $promptDirectory = __DIR__)
    {
    }

    public function definition(): Prompt
    {
        return new Prompt(
            name: self::NAME,
            title: 'Explain my notebook',
            description: 'How to read one of your notebooks, explain its pins one by one from the code, and, when you'
                .' ask for cases, write scenarios the pinround extension plays in your app and save them in the'
                .' notebook. Takes no arguments.',
        );
    }

    /**
     * @param array<string, string> $arguments always empty: definition() lists none, so GatedPromptGetHandler refuses any
     */
    public function text(array $arguments = []): string
    {
        return VersionedPromptFile::read($this->promptDirectory, self::VERSION);
    }
}
