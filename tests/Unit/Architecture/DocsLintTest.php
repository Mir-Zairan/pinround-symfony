<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves bin/lint-docs bites, on the fixtures in tests/Fixtures/DocsLint:
 * every file in Bad/ is reported at its line, no file in Good/ is, and the real repository passes.
 * The bad fixtures are deliberate. Do not "fix" them.
 */
final class DocsLintTest extends TestCase
{
    private const string FIXTURES = 'tests/Fixtures/DocsLint/';
    private const string LONG_REAL_DOC = 'docs/05-plan/phase-00-foundation.md';

    private ?string $temporaryFolder = null;

    #[Test]
    public function everyProblemIsReportedAtItsLine(): void
    {
        $process = self::runLint(self::FIXTURES.'Bad');

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertEqualsCanonicalizing(
            [
                self::FIXTURES.'Bad/index_after_line_40.md:41',
                self::FIXTURES.'Bad/long_without_index.md:1',
                self::FIXTURES.'Bad/missing_anchor_other_file.md:5',
                self::FIXTURES.'Bad/missing_same_file_anchor.md:5',
                self::FIXTURES.'Bad/missing_root_relative_target.md:3',
                self::FIXTURES.'Bad/missing_target_file.md:3',
            ],
            self::reportedLocations($process),
        );
    }

    #[Test]
    public function theReportNamesTheReason(): void
    {
        $process = self::runLint(self::FIXTURES.'Bad');

        $output = $process->getOutput();
        self::assertStringContainsString('long_without_index.md:1: 101 lines and no `## Index` in the first 40 lines', $output);
        self::assertStringContainsString('index_after_line_40.md:41: `## Index` must be within the first 40 lines', $output);
        self::assertStringContainsString('missing_target_file.md:3: broken link, no such file or folder: does-not-exist.md', $output);
        self::assertStringContainsString('missing_root_relative_target.md:3: broken link, no such file or folder: /tests/Fixtures/DocsLint/Bad/no-such-file.md', $output);
        self::assertStringContainsString('missing_anchor_other_file.md:5: broken anchor, no such heading: target.md#no-such-heading', $output);
        self::assertStringContainsString('missing_same_file_anchor.md:5: broken anchor, no such heading: #absent', $output);
    }

    #[Test]
    public function goodDocsAreNotReported(): void
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
    public function theRepositoryPassesAndSkipsItsOwnFixtures(): void
    {
        $process = self::runLint();

        self::assertSame(0, $process->getExitCode(), self::outputOf($process));
        self::assertSame('', $process->getOutput());
    }

    #[Test]
    public function aRealLongDocLosingItsIndexFails(): void
    {
        $original = (string) file_get_contents(self::repositoryRoot().'/'.self::LONG_REAL_DOC);
        self::assertGreaterThan(100, substr_count($original, "\n"), 'the doc this test strips is no longer over 100 lines');
        $withoutIndex = preg_replace('/^## Index\R/m', '', $original, -1, $removed);
        self::assertSame(1, $removed, 'the doc this test strips no longer has exactly one `## Index` line');
        self::assertIsString($withoutIndex);

        $this->temporaryFolder = sys_get_temp_dir().'/docs-lint-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryFolder);
        file_put_contents($this->temporaryFolder.'/with-index.md', $original);
        file_put_contents($this->temporaryFolder.'/without-index.md', $withoutIndex);

        $process = self::runLint($this->temporaryFolder.'/without-index.md');

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertContains($this->temporaryFolder.'/without-index.md:1', self::reportedLocations($process));
        self::assertStringContainsString('no `## Index`', $process->getOutput());
        self::assertNotContains(
            $this->temporaryFolder.'/with-index.md:1',
            self::reportedLocations(self::runLint($this->temporaryFolder.'/with-index.md')),
        );
    }

    protected function tearDown(): void
    {
        if (null === $this->temporaryFolder) {
            return;
        }
        foreach (['with-index.md', 'without-index.md'] as $file) {
            $file = $this->temporaryFolder.'/'.$file;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->temporaryFolder);
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function runLint(string ...$paths): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/lint-docs', ...$paths], self::repositoryRoot());
        $process->run();

        return $process;
    }

    private static function outputOf(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @return list<string> `path:line` of each reported problem
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
