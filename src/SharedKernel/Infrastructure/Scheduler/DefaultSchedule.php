<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Empty on purpose: the scheduler service needs a `default` schedule to start; P00.T7 adds the real recurring jobs.
 */
#[AsSchedule('default')]
final class DefaultSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule();
    }
}
