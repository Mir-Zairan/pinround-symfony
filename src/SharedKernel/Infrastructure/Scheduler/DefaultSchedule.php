<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The `default` schedule every module's recurring job joins: modules add them with #[AsCronTask] or #[AsPeriodicTask]
 * on their own handlers, never by editing this class (operations.md, "Scheduled jobs").
 *
 * The scheduler worker restarts every hour (compose.yaml), and a periodic task counts its period from the moment the
 * schedule first ran. Without the state kept in the state Valkey, each restart would start the count again and a
 * '1 day' task would never fire. The lock lets one scheduler worker at a time run the schedule.
 */
#[AsSchedule('default')]
final readonly class DefaultSchedule implements ScheduleProviderInterface
{
    public const string LOCK_NAME = 'scheduler_default';

    public function __construct(
        #[Autowire(service: 'cache.scheduler')]
        private CacheInterface $state,
        #[Autowire(service: 'lock.factory')]
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        // After a long stop, each job runs once for the runs it missed, not once per missed run.
        return new Schedule()
            ->stateful($this->state)
            ->lock($this->lockFactory->createLock(self::LOCK_NAME))
            ->processOnlyLastMissedRun(true);
    }
}
