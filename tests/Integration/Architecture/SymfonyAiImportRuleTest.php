<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proof that only App\Assist\Infrastructure\Platform may use Symfony AI (ADR-0018), run on fixtures in
 * tests/Fixtures/Deptrac:
 * - SymfonyAiOutsidePlatform: Assist's other Infrastructure and Ticketing's Infrastructure use it, and must fail;
 * - SymfonyAiInPlatform: the Platform folder uses it with its own Application and other vendor code, and must pass.
 */
final class SymfonyAiImportRuleTest extends TestCase
{
    #[Test]
    public function symfonyAiOutsideAssistPlatformFailsDeptrac(): void
    {
        $process = DeptracRunner::run('SymfonyAiOutsidePlatform');
        $output = DeptracRunner::outputOf($process);

        self::assertNotSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString('Assist_Infrastructure on SymfonyAi', $output);
        self::assertStringContainsString('Ticketing_Infrastructure on SymfonyAi', $output);
        self::assertStringContainsString('Violations: 4', $output);
        self::assertStringContainsString('Uncovered: 0', $output);
    }

    #[Test]
    public function symfonyAiInsideAssistPlatformPassesDeptrac(): void
    {
        $process = DeptracRunner::run('SymfonyAiInPlatform');
        $output = DeptracRunner::outputOf($process);

        self::assertSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString('Violations: 0', $output);
        self::assertStringContainsString('Uncovered: 0', $output);
    }
}
