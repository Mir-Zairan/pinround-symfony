<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\Web\UI\Controller\HealthController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HealthControllerTest extends KernelTestCase
{
    #[Test]
    public function readyRunsEveryReadinessCheckWithTheDatabaseFirst(): void
    {
        $healthController = self::getContainer()->get(HealthController::class);
        $readinessChecks = new \ReflectionProperty(HealthController::class, 'readinessChecks')->getValue($healthController);
        self::assertIsIterable($readinessChecks);

        $names = [];
        foreach ($readinessChecks as $readinessCheck) {
            self::assertInstanceOf(ReadinessCheck::class, $readinessCheck);
            $names[] = $readinessCheck->name();
        }

        // The checks that query the database rely on it having answered within its short timeout first.
        self::assertSame('database', $names[0] ?? null);
        self::assertEqualsCanonicalizing(
            ['database', 'migrations', 'next_audit_partition', 'cache_valkey', 'state_valkey', 'object_storage'],
            $names,
        );
    }
}
