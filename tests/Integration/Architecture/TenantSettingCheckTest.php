<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves bin/check-tenant-setting bites, on the fixtures in tests/Fixtures/TenantSetting:
 * every file in Bad/ is reported at its offending line(s), no file in Good/ is, and the real repository passes.
 * Files in AllowListed/, and TenantConnection with its third argument flipped, are scanned under an allow-listed
 * path in a throwaway copy of the repository, to prove the rules that hold even there.
 * The bad fixtures are deliberate. Do not "fix" them.
 */
final class TenantSettingCheckTest extends TestCase
{
    private const string FIXTURES = 'tests/Fixtures/TenantSetting/';
    private const string TENANT_CONNECTION = 'src/SharedKernel/Infrastructure/Doctrine/TenantConnection.php';
    private const string TRANSACTION_LOCAL_ARGUMENTS = '?, true)';

    private ?string $temporaryRoot = null;

    #[Test]
    public function everyBannedFormIsReportedAtItsLine(): void
    {
        $process = self::runCheck(self::FIXTURES.'Bad');

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertEqualsCanonicalizing(
            [
                self::FIXTURES.'Bad/escaped_quotes.php:5',
                self::FIXTURES.'Bad/escaped_quotes.php:6',
                self::FIXTURES.'Bad/in_php.php:5',
                self::FIXTURES.'Bad/in_shell.sh:2',
                self::FIXTURES.'Bad/in_yaml.yaml:2',
                self::FIXTURES.'Bad/mixed_case_and_spacing.sql:2',
                self::FIXTURES.'Bad/mixed_case_and_spacing.sql:4',
                self::FIXTURES.'Bad/php_without_extension:3',
                self::FIXTURES.'Bad/reset.sql:2',
                self::FIXTURES.'Bad/script_without_extension:4',
                self::FIXTURES.'Bad/set_config_false.sql:2',
                self::FIXTURES.'Bad/set_config_false_multiline.sql:2',
                self::FIXTURES.'Bad/set_config_true_outside_middleware.sql:2',
                self::FIXTURES.'Bad/set_from_constant.php:5',
                self::FIXTURES.'Bad/set_from_constant.php:6',
                self::FIXTURES.'Bad/set_from_constant.php:7',
                self::FIXTURES.'Bad/set_local.sql:2',
                self::FIXTURES.'Bad/set_plain.sql:2',
                self::FIXTURES.'Bad/set_session.sql:2',
                self::FIXTURES.'Bad/shell_script_without_extension:2',
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

    #[Test]
    public function theMiddlewareStatementPassesAsWritten(): void
    {
        $inTheRepository = self::runCheck(self::TENANT_CONNECTION);
        $inACopy = self::runCheckIn(
            $this->temporaryRepositoryWith([self::TENANT_CONNECTION => self::tenantConnectionSource()]),
            self::TENANT_CONNECTION,
        );

        self::assertSame(0, $inTheRepository->getExitCode(), self::outputOf($inTheRepository));
        self::assertSame(0, $inACopy->getExitCode(), self::outputOf($inACopy));
        self::assertSame('', $inACopy->getOutput());
    }

    #[Test]
    #[DataProvider('thirdArgumentsOtherThanTrue')]
    public function aSetConfigThatIsNotTransactionLocalIsReportedEvenInTheMiddleware(string $thirdArgument): void
    {
        $source = self::tenantConnectionSource();
        $flipped = str_replace(self::TRANSACTION_LOCAL_ARGUMENTS, '?, '.$thirdArgument.')', $source, $replacements);
        self::assertSame(1, $replacements, 'TenantConnection no longer holds the statement this test flips');

        $process = self::runCheckIn($this->temporaryRepositoryWith([self::TENANT_CONNECTION => $flipped]), self::TENANT_CONNECTION);

        self::assertSame(1, $process->getExitCode(), self::outputOf($process));
        self::assertSame([self::TENANT_CONNECTION.':'.self::lineOf($source, self::TRANSACTION_LOCAL_ARGUMENTS)], self::reportedLocations($process));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function thirdArgumentsOtherThanTrue(): iterable
    {
        foreach (['false', 'FALSE', "'false'", "'off'", "'no'", "'f'", "'0'", '0', '$isLocal'] as $thirdArgument) {
            yield 'third argument '.$thirdArgument => [$thirdArgument];
        }
    }

    #[Test]
    public function theThirdArgumentIsFoundPastParenthesesButNotPastTheEndOfTheCall(): void
    {
        $allowedFile = 'src/SharedKernel/Infrastructure/Doctrine/TenantMiddleware.php';
        $source = (string) file_get_contents(self::repositoryRoot().'/'.self::FIXTURES.'AllowListed/parenthesised_second_argument.php');

        $process = self::runCheckIn($this->temporaryRepositoryWith([$allowedFile => $source]), $allowedFile);

        self::assertSame([$allowedFile.':7'], self::reportedLocations($process), self::outputOf($process));
    }

    #[Test]
    public function aPathThatDoesNotExistIsAnError(): void
    {
        $process = self::runCheck(self::FIXTURES.'Good', self::FIXTURES.'Missing');

        self::assertSame(2, $process->getExitCode(), self::outputOf($process));
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString(self::FIXTURES.'Missing', $process->getErrorOutput());
    }

    protected function tearDown(): void
    {
        if (null === $this->temporaryRoot) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->temporaryRoot);
    }

    /**
     * A throwaway repository holding only a copy of the check and the given files, so a file on the real
     * allow-list can be scanned with content the real repository must never hold.
     *
     * @param array<string, string> $files repository-relative path => content
     */
    private function temporaryRepositoryWith(array $files): string
    {
        $this->temporaryRoot = sys_get_temp_dir().'/tenant-setting-check-'.bin2hex(random_bytes(6));
        $files['bin/check-tenant-setting'] = (string) file_get_contents(self::repositoryRoot().'/bin/check-tenant-setting');
        foreach ($files as $path => $content) {
            $absolute = $this->temporaryRoot.'/'.$path;
            if (!is_dir(\dirname($absolute))) {
                mkdir(\dirname($absolute), 0o777, true);
            }
            file_put_contents($absolute, $content);
        }

        return $this->temporaryRoot;
    }

    private static function tenantConnectionSource(): string
    {
        return (string) file_get_contents(self::repositoryRoot().'/'.self::TENANT_CONNECTION);
    }

    private static function lineOf(string $content, string $needle): int
    {
        $offset = strpos($content, $needle);
        self::assertNotFalse($offset);

        return substr_count($content, "\n", 0, $offset) + 1;
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function runCheck(string ...$paths): Process
    {
        return self::runCheckIn(self::repositoryRoot(), ...$paths);
    }

    private static function runCheckIn(string $root, string ...$paths): Process
    {
        $process = new Process([\PHP_BINARY, 'bin/check-tenant-setting', ...$paths], $root);
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
