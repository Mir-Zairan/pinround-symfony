<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Documented proof that the module rules bite: the fixture's Review Application class imports
 * Ticketing's Domain on purpose, so Deptrac must fail on it. Do not "fix" the fixture import.
 */
final class ModuleBoundaryViolationTest extends TestCase
{
    #[Test]
    public function reviewApplicationImportingTicketingDomainFailsDeptrac(): void
    {
        $process = DeptracRunner::run('ReviewUsesTicketingDomain');

        self::assertNotSame(0, $process->getExitCode(), DeptracRunner::outputOf($process));
        self::assertStringContainsString('Review_Application on Ticketing_Domain', DeptracRunner::outputOf($process));
    }

    #[Test]
    public function aClassInNoLayerFailsDeptracAsUncovered(): void
    {
        $process = DeptracRunner::run('ReviewUsesUncoveredClass');

        self::assertNotSame(0, $process->getExitCode(), DeptracRunner::outputOf($process));
        self::assertStringContainsString('Review\Helpers\Formatter', DeptracRunner::outputOf($process));
        self::assertStringContainsString('Uncovered: 1', DeptracRunner::outputOf($process));
    }

    #[Test]
    public function allowedDependenciesPassDeptrac(): void
    {
        $process = DeptracRunner::run('AllowedDependencies');

        self::assertSame(0, $process->getExitCode(), DeptracRunner::outputOf($process));
        self::assertStringContainsString('Violations: 0', DeptracRunner::outputOf($process));
        self::assertStringContainsString('Uncovered: 0', DeptracRunner::outputOf($process));
    }
}
