<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Documented proof that the module rules bite, run on fixtures in tests/Fixtures/Deptrac:
 * - ReviewUsesTicketingDomain: forbidden edge into another module's Domain;
 * - ReviewUsesUncoveredClass: a class in no layer;
 * - AuditUsesTicketingApi: an Api call outside the module's "Depends on" line;
 * - AllowedDependencies: an allowed Api call and an allowed event use must pass.
 * The violations are deliberate. Do not "fix" the fixture imports.
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
    public function auditInfrastructureUsingTicketingApiFailsDeptrac(): void
    {
        $process = DeptracRunner::run('AuditUsesTicketingApi');

        self::assertNotSame(0, $process->getExitCode(), DeptracRunner::outputOf($process));
        self::assertStringContainsString('Audit_Infrastructure on Ticketing_Api', DeptracRunner::outputOf($process));
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
