<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Scheduler;

use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use App\SharedKernel\Infrastructure\Scheduler\DefaultScheduleLock;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

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
        self::assertSame(self::getContainer()->get(DefaultScheduleLock::class), $schedule->getLock());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }

    #[Test]
    public function theWorkerRenewsAndFreesTheSameLockTheScheduleTakes(): void
    {
        self::bootKernel();
        $lock = self::getContainer()->get(DefaultScheduleLock::class);
        $dispatcher = self::getContainer()->get('event_dispatcher');

        self::assertSame([[$lock, 'renewWhileWorkerRuns']], self::listenersOn($lock, $dispatcher->getListeners(WorkerRunningEvent::class)));
        self::assertSame([[$lock, 'releaseWhenWorkerStops']], self::listenersOn($lock, $dispatcher->getListeners(WorkerStoppedEvent::class)));
    }

    /**
     * @param array<callable|array{object, string}> $listeners
     *
     * @return list<array{object, string}>
     */
    private static function listenersOn(object $target, array $listeners): array
    {
        $onTarget = [];
        foreach ($listeners as $listener) {
            if (\is_array($listener) && $listener[0] === $target) {
                $onTarget[] = $listener;
            }
        }

        return $onTarget;
    }
}
