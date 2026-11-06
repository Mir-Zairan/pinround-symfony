<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use Symfony\Component\Process\Process;

/**
 * Runs Deptrac on a fixture with the same analyse arguments as the `deptrac` composer script,
 * so the tests cannot drift from the flags CI uses.
 */
final readonly class DeptracRunner
{
    private const string FIXTURES = 'tests/Fixtures/Deptrac/';

    public static function run(string $fixture): Process
    {
        $process = new Process(
            [
                \PHP_BINARY,
                'vendor/bin/deptrac',
                ...self::scriptArguments(),
                '--config-file='.self::FIXTURES.$fixture.'/deptrac.yaml',
                '--formatter=console',
            ],
            self::projectDir(),
        );
        $process->run();

        return $process;
    }

    public static function outputOf(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @return list<string> everything after `deptrac` in the composer script, e.g. `analyse --fail-on-uncovered`
     */
    private static function scriptArguments(): array
    {
        /** @var array{scripts: array{deptrac: string}} $composer */
        $composer = json_decode((string) file_get_contents(self::projectDir().'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        $words = explode(' ', $composer['scripts']['deptrac']);
        \assert('deptrac' === $words[0]);

        return \array_slice($words, 1);
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
