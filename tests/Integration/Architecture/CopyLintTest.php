<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves bin/lint-copy bites, on the fixtures in tests/Fixtures/CopyLint:
 * every banned phrase in Bad/ is reported at its line, nothing in Good/ is, and the real repository passes.
 * The bad fixtures are deliberate. Do not "fix" them.
 */
final class CopyLintTest extends TestCase
{
    private const string FIXTURES = 'tests/Fixtures/CopyLint/';

    private ?string $temporaryFile = null;

    #[Test]
    public function everyBannedPhraseIsReportedAtItsLine(): void
    {
        $process = self::runLint(self::FIXTURES.'Bad');

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertEqualsCanonicalizing(
            [
                self::FIXTURES.'Bad/workflow_status.html.twig:2',
                self::FIXTURES.'Bad/workflow_status.html.twig:4',
                self::FIXTURES.'Bad/glossary_word.html.twig:3',
                self::FIXTURES.'Bad/glossary_word.html.twig:4',
                self::FIXTURES.'Bad/plural.html.twig:2',
                self::FIXTURES.'Bad/attribute.html.twig:2',
                self::FIXTURES.'Bad/attribute.html.twig:3',
                self::FIXTURES.'Bad/attribute.html.twig:4',
                self::FIXTURES.'Bad/literal.html.twig:2',
                self::FIXTURES.'Bad/literal.html.twig:3',
                self::FIXTURES.'Bad/after_comment.html.twig:5',
                self::FIXTURES.'Bad/translations.yaml:4',
                self::FIXTURES.'Bad/translations.yaml:6',
                self::FIXTURES.'Bad/translations.yaml:9',
                self::FIXTURES.'Bad/messages.xlf:7',
            ],
            self::reportedLocations($process),
        );
    }

    #[Test]
    public function theReportNamesTheBannedPhraseAndWhatToSayInstead(): void
    {
        $output = self::runLint(self::FIXTURES.'Bad')->getOutput();

        self::assertStringContainsString(self::FIXTURES.'Bad/workflow_status.html.twig:2: "Workflow Status" — say "status"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/workflow_status.html.twig:4: "status changed" — say "moved"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/glossary_word.html.twig:3: "Issue" — say "Ticket"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/plural.html.twig:2: "watchers" — say "(follows My reports / mutes updates)"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/attribute.html.twig:2: "marker" — say "Pin"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/literal.html.twig:3: "transition" — say "action / move"', $output);
        self::assertStringContainsString(self::FIXTURES.'Bad/translations.yaml:9: "story points" — say "(not in v1)"', $output);
    }

    #[Test]
    public function goodCopyIsNotReported(): void
    {
        $process = self::runLint(self::FIXTURES.'Good');

        self::assertSame(0, $process->getExitCode(), self::outputOf($process));
        self::assertSame([], self::reportedLocations($process));
    }

    #[Test]
    public function aPathThatDoesNotExistIsAnError(): void
    {
        $process = self::runLint(self::FIXTURES.'Good', self::FIXTURES.'Missing');

        self::assertSame(2, $process->getExitCode(), self::outputOf($process));
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString(self::FIXTURES.'Missing', $process->getErrorOutput());
    }

    #[Test]
    public function aGlossaryWithoutTheTableIsAnError(): void
    {
        $process = self::runLint('--glossary', self::FIXTURES.'Glossary/no_table.md', self::FIXTURES.'Good');

        self::assertSame(2, $process->getExitCode(), self::outputOf($process));
        self::assertStringContainsString('Words we do not use', $process->getErrorOutput());
    }

    #[Test]
    public function aGlossaryTableWithoutWordsIsAnError(): void
    {
        $process = self::runLint('--glossary', self::FIXTURES.'Glossary/empty_table.md', self::FIXTURES.'Good');

        self::assertSame(2, $process->getExitCode(), self::outputOf($process));
    }

    #[Test]
    public function aMissingGlossaryFileIsAnError(): void
    {
        $process = self::runLint('--glossary', self::FIXTURES.'Glossary/absent.md', self::FIXTURES.'Good');

        self::assertSame(2, $process->getExitCode(), self::outputOf($process));
    }

    #[Test]
    public function theBannedWordsComeFromTheGlossaryAtRunTime(): void
    {
        $this->temporaryFile = sys_get_temp_dir().'/lint-copy-'.bin2hex(random_bytes(6)).'.html.twig';
        file_put_contents($this->temporaryFile, "<p>Omar adds a Gizmo.</p>\n<p>Mia reads two widgets and a watcher.</p>\n");

        $process = self::runLint('--glossary', self::FIXTURES.'Glossary/one_word.md', $this->temporaryFile);

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertEqualsCanonicalizing([$this->temporaryFile.':1', $this->temporaryFile.':2'], self::reportedLocations($process));
        self::assertStringContainsString('"Gizmo" — say "Pin"', $process->getOutput());
        self::assertStringNotContainsString('watcher"', $process->getOutput());
    }

    #[Test]
    public function theRepositoryPassesAndSkipsItsOwnFixtures(): void
    {
        $process = self::runLint();

        self::assertSame(0, $process->getExitCode(), self::outputOf($process));
        self::assertSame('', $process->getOutput());
    }

    protected function tearDown(): void
    {
        if (null !== $this->temporaryFile && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function runLint(string ...$arguments): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/lint-copy', ...$arguments], self::repositoryRoot());
        $process->run();

        return $process;
    }

    private static function outputOf(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @return list<string> `path:line` of each reported hit
     */
    private static function reportedLocations(Process $process): array
    {
        $locations = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ('' !== $line && 1 === preg_match('/^(\S+:\d+): /', $line, $match)) {
                $locations[] = $match[1];
            }
        }

        return $locations;
    }
}
