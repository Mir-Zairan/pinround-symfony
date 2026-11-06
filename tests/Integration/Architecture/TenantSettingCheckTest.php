<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves bin/check-tenant-setting bites, on the fixtures in tests/Fixtures/TenantSetting:
 * every file in Bad/ is reported at its offending line(s), no file in Good/ is, and the real repository passes.
 * The bad fixtures are deliberate. Do not "fix" them.
 */
final class TenantSettingCheckTest extends TestCase
{
    private const string FIXTURES = 'tests/Fixtures/TenantSetting/';

    #[Test]
    public function everyBannedFormIsReportedAtItsLine(): void
    {
        $process = self::runCheck(self::FIXTURES.'Bad');

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertEqualsCanonicalizing(
            [
                self::FIXTURES.'Bad/in_php.php:5',
                self::FIXTURES.'Bad/in_shell.sh:2',
                self::FIXTURES.'Bad/in_yaml.yaml:2',
                self::FIXTURES.'Bad/mixed_case_and_spacing.sql:2',
                self::FIXTURES.'Bad/mixed_case_and_spacing.sql:4',
                self::FIXTURES.'Bad/reset.sql:2',
                self::FIXTURES.'Bad/set_config_false.sql:2',
                self::FIXTURES.'Bad/set_config_false_multiline.sql:2',
                self::FIXTURES.'Bad/set_config_true_outside_middleware.sql:2',
                self::FIXTURES.'Bad/set_local.sql:2',
                self::FIXTURES.'Bad/set_plain.sql:2',
                self::FIXTURES.'Bad/set_session.sql:2',
            ],
            self::reportedLocations($process),
        );
    }

    #[Test]
    public function readsAndMentionsAreNotReported(): void
    {
        $process = self::runCheck(self::FIXTURES.'Good');

        self::assertSame(0, $process->getExitCode(), self::outputOf($process));
        self::assertSame([], self::reportedLocations($process));
    }

    #[Test]
    public function theReportPrintsTheOffendingText(): void
    {
        $process = self::runCheck(self::FIXTURES.'Bad/set_plain.sql');

        $offendingLine = trim(explode("\n", (string) file_get_contents(\dirname(__DIR__, 3).'/'.self::FIXTURES.'Bad/set_plain.sql'))[1]);

        self::assertStringContainsString(self::FIXTURES.'Bad/set_plain.sql:2: '.$offendingLine, $process->getOutput());
    }

    #[Test]
    public function theRepositoryPassesAndSkipsItsOwnFixtures(): void
    {
        $process = self::runCheck();

        self::assertSame(0, $process->getExitCode(), self::outputOf($process));
        self::assertSame('', $process->getOutput());
    }

    private static function runCheck(string ...$paths): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/check-tenant-setting', ...$paths], \dirname(__DIR__, 3));
        $process->run();

        return $process;
    }

    private static function outputOf(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @return list<string> `path:line` of each reported violation
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
