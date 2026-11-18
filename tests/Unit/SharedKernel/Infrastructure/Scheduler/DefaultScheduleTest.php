<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Scheduler;

use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Each "process" below is a new DefaultSchedule and MessageGenerator over the same cache and lock store, as a scheduler
 * worker that compose.yaml restarts every hour gets: only the state Valkey outlives it.
 */
final class DefaultScheduleTest extends TestCase
{
    private const string FIRST_START = '2026-03-04 10:00:00 UTC';

    private ArrayAdapter $state;
    private LockFactory $lockFactory;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->state = new ArrayAdapter();
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->clock = new MockClock(self::FIRST_START);
    }

    #[Test]
    public function aDailyJobRunsOnceADayAlthoughTheWorkerRestartsEveryHour(): void
    {
        $runHours = [];
        for ($hour = 0; $hour <= 50; ++$hour) {
            if (0 !== $this->runDueJobsInANewProcess()) {
                $runHours[] = $hour;
            }
            $this->clock->modify('+1 hour');
        }

        self::assertSame([24, 48], $runHours);
    }

    #[Test]
    public function aSecondWorkerRunsNothingWhileTheFirstHoldsTheLock(): void
    {
        $firstWorker = $this->newGenerator();
        self::assertSame(0, self::countDueJobs($firstWorker));

        $this->clock->modify('+1 day +1 minute');

        self::assertSame(0, $this->runDueJobsInANewProcess());
        self::assertSame(1, self::countDueJobs($firstWorker));
    }

    #[Test]
    public function afterThreeDaysStoppedADailyJobRunsOnceNotThreeTimes(): void
    {
        self::assertSame(0, $this->runDueJobsInANewProcess());

        $this->clock->modify('+3 days +1 hour');

        self::assertSame(1, $this->runDueJobsInANewProcess());
    }

    #[Test]
    public function theStatePoolIsOnTheStateValkeyInEveryEnvironment(): void
    {
        $cache = Yaml::parseFile(\dirname(__DIR__, 5).'/config/packages/cache.yaml');
        self::assertIsArray($cache);

        self::assertSame(
            ['adapter' => 'cache.adapter.redis', 'provider' => 'state_valkey.connection'],
            self::schedulerPoolIn($cache),
        );
        $overridingEnvironments = [];
        foreach ($cache as $name => $environment) {
            if (str_starts_with((string) $name, 'when@') && null !== self::schedulerPoolIn($environment)) {
                $overridingEnvironments[] = $name;
            }
        }
        self::assertArrayHasKey('when@test', $cache);
        self::assertSame([], $overridingEnvironments);
    }

    private static function schedulerPoolIn(mixed $config): mixed
    {
        foreach (['framework', 'cache', 'pools', 'cache.scheduler'] as $key) {
            if (!\is_array($config) || !\array_key_exists($key, $config)) {
                return null;
            }
            $config = $config[$key];
        }

        return $config;
    }

    /**
     * The jobs due now, from a worker that ends (and so frees the lock) before this returns.
     */
    private function runDueJobsInANewProcess(): int
    {
        return self::countDueJobs($this->newGenerator());
    }

    private function newGenerator(): MessageGenerator
    {
        $schedule = new DefaultSchedule($this->state, $this->lockFactory)->getSchedule()
            ->add(RecurringMessage::every('1 day', new \stdClass()));

        return new MessageGenerator($schedule, 'default', $this->clock);
    }

    private static function countDueJobs(MessageGenerator $generator): int
    {
        $count = 0;
        foreach ($generator->getMessages() as $ignored) {
            ++$count;
        }

        return $count;
    }
}
