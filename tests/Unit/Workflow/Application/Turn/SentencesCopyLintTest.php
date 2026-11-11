<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/lint-copy reads only twig, yaml and xlf files, so the sentences in PHP are linted here by writing them to a
 * temporary yaml file.
 */
final class SentencesCopyLintTest extends TestCase
{
    private ?string $temporaryFile = null;

    #[Test]
    public function everySentenceTheTestsExpectPassesTheCopyLint(): void
    {
        $sentences = self::sentences();
        self::assertGreaterThan(50, \count($sentences));

        $process = $this->lint($sentences);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    #[Test]
    public function theLintReadsTheTemporaryFileAndReportsABannedPhrase(): void
    {
        $process = $this->lint([...self::sentences(), 'Pick a transition']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('"transition"', $process->getOutput());
    }

    protected function tearDown(): void
    {
        if (null !== $this->temporaryFile && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    /**
     * @return list<string>
     */
    private static function sentences(): array
    {
        $sentences = [];
        foreach ([PlainNowNextTest::nowSentences(), PlainNowNextTest::nextSentences(), PlainVerbForTest::verbs()] as $rows) {
            foreach ($rows as $row) {
                if (null !== $row[3]) {
                    $sentences[] = $row[3];
                }
            }
        }

        return array_values(array_unique($sentences));
    }

    /**
     * @param list<string> $sentences
     */
    private function lint(array $sentences): Process
    {
        $lines = [];
        foreach ($sentences as $index => $sentence) {
            $lines[] = 's'.$index.': '.json_encode($sentence, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }
        $this->temporaryFile = sys_get_temp_dir().'/lint-copy-sentences-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($this->temporaryFile, implode("\n", $lines)."\n");

        $process = new Process([\PHP_BINARY, 'bin/lint-copy', $this->temporaryFile], \dirname(__DIR__, 5));
        $process->run();

        return $process;
    }
}
