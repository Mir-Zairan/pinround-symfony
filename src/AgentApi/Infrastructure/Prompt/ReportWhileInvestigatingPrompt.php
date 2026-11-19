<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

use Mcp\Schema\Prompt;

/**
 * The MCP prompt report_while_investigating (P13.T4, FR-MCP-14, ADR-0045): how a client keeps an evidence log while it
 * checks an app and files it in one step at the end. Its text is the versioned file VERSION names, beside this class;
 * a change of wording is a new file and a new VERSION. Holds no state (ADR-0008): the file is read on each call.
 */
final readonly class ReportWhileInvestigatingPrompt
{
    public const string NAME = 'report_while_investigating';
    public const string VERSION = 'report_while_investigating.v1';

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
            title: 'Report while you investigate',
            description: 'How to keep an evidence log (pictures, titles, summaries and pins) while you check an app,'
                .' and file it at the end as a report draft or into your draft round on a ticket. The person sends the'
                .' report in pinround. Takes no arguments.',
        );
    }

    /**
     * @return string the file's text, byte for byte
     *
     * @throws \RuntimeException when the file cannot be read, so the prompt is refused rather than answered empty
     */
    public function text(): string
    {
        $path = $this->promptDirectory.'/'.self::VERSION.'.md';
        $text = is_readable($path) ? file_get_contents($path) : false;
        if (false === $text || '' === $text) {
            throw new \RuntimeException(\sprintf('The prompt file %s.md is missing or empty.', self::VERSION));
        }

        return $text;
    }
}
