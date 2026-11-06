<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Documented proof that the module rules bite: the fixture's Review Application class imports
 * Ticketing's Domain on purpose, so Deptrac must fail on it. Do not "fix" the fixture import.
 */
final class ModuleBoundaryViolationTest extends TestCase
{
    private const string FIXTURE_CONFIG = 'tests/Fixtures/Deptrac/ReviewUsesTicketingDomain/deptrac.yaml';

    #[Test]
    public function reviewApplicationImportingTicketingDomainFailsDeptrac(): void
    {
        $process = new Process(
            [\PHP_BINARY, 'vendor/bin/deptrac', 'analyse', '--config-file='.self::FIXTURE_CONFIG, '--no-progress', '--no-cache', '--fail-on-uncovered', '--report-uncovered', '--formatter=console'],
            \dirname(__DIR__, 3),
        );
        $process->run();

        self::assertNotSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString(
            'Review_Application on Ticketing_Domain',
            $process->getOutput().$process->getErrorOutput(),
        );
    }
}
