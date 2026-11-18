<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Scheduler;

use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schedule the scheduler worker really runs: DefaultSchedule with every module's #[AsPeriodicTask] and
 * #[AsCronTask] job added by the scheduler's own decorator.
 */
final class DefaultScheduleTest extends KernelTestCase
{
    #[Test]
    public function theScheduleWithEveryModulesJobsKeepsItsStateInTheSchedulerPoolAndTakesALock(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(DefaultSchedule::class);

        self::assertNotSame([], $schedule->getRecurringMessages());
        self::assertSame(self::getContainer()->get('cache.scheduler'), $schedule->getState());
        self::assertNotNull($schedule->getLock());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }
}
