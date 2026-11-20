<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Prompt;

use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The versioned prompt file and what P13.T4 says it must tell a client: the evidence log, the caps, no upload while
 * checking, the three choices at the end, the order of the uploads, and that the person sends the report.
 */
final class ReportWhileInvestigatingPromptTest extends TestCase
{
    private const string PROMPT_FILE = __DIR__.'/../../../../../src/AgentApi/Infrastructure/Prompt/report_while_investigating.v1.md';

    #[Test]
    public function theTextIsTheVersionedFileByteForByte(): void
    {
        $fileText = file_get_contents(self::PROMPT_FILE);
        self::assertIsString($fileText);

        self::assertSame($fileText, new ReportWhileInvestigatingPrompt()->text());
    }

    #[Test]
    public function theTextTellsTheClientTheWorkingMethodTheCapsAndThatThePersonSends(): void
    {
        $text = new ReportWhileInvestigatingPrompt()->text();

        foreach ([
            'an ordered evidence log in a local file',
            'the "before" picture',
            'at most 120 characters',
            'at most 600 characters',
            'a note of at most 1,000 characters',
            'put the pin at its centre',
            'Do not upload anything while you are still checking.',
            'One report holds at most 10 pictures.',
            'Call `find_tickets`',
            '"New report"',
            '"Add to <key>", for each ticket where `may_start_draft` is true',
            '"Check more"',
            '`create_report_upload` with the project key, then PUT the file to `put_url`',
            'Then one `create_report` call',
            '`create_upload` with the key, then PUT the file to `put_url`',
            'then `add_screenshot`',
            'checks it and presses Send. No tool you have can make a ticket or send a report.',
        ] as $instruction) {
            self::assertStringContainsString($instruction, $text);
        }
    }

    #[Test]
    public function aMissingFileIsRefusedRatherThanAnsweredEmpty(): void
    {
        $prompt = new ReportWhileInvestigatingPrompt(__DIR__.'/no-such-folder');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The prompt file report_while_investigating.v1.md is missing or empty.');

        $prompt->text();
    }

    #[Test]
    public function theDefinitionNamesThePromptAndTakesNoArguments(): void
    {
        $definition = new ReportWhileInvestigatingPrompt()->definition();

        self::assertSame('report_while_investigating', $definition->name);
        self::assertNull($definition->arguments);
        self::assertSame(['name', 'title', 'description'], array_keys($definition->jsonSerialize()));
    }
}
