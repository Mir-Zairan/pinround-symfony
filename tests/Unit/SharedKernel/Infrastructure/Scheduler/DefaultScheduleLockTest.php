<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Scheduler;

use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use App\SharedKernel\Infrastructure\Scheduler\DefaultScheduleLock;
use App\Tests\Fixtures\Lock\ClockedLockStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Each scheduler worker below is its own DefaultScheduleLock, DefaultSchedule and MessageGenerator over one state cache
 * and one lock store that expires locks by the test clock, as the state Valkey does. A job runs every minute, counted
 * from the first worker's start, so the first run is due at FIRST_START + 60 s.
 */
final class DefaultScheduleLockTest extends TestCase
{
    private const string FIRST_START = '2026-03-04 10:00:00 UTC';

    private ArrayAdapter $state;
    private LockFactory $lockFactory;
    private MockClock $clock;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::FIRST_START);
        $this->state = new ArrayAdapter();
        $this->lockFactory = new LockFactory(new ClockedLockStore($this->clock));
        $this->worker = new Worker([], new MessageBus());
    }

    #[Test]
    public function aKilledWorkersLockPassesToTheNextWorkerOnceItsTtlRunsOut(): void
    {
        $killedLock = new DefaultScheduleLock($this->lockFactory);
        $killedWorker = $this->newGenerator($killedLock);
        // Killed before the end of its turn, so before its WorkerRunningEvent could renew the lock.
        self::assertSame(0, self::runDueJobs($killedWorker));

        $this->clock->sleep(DefaultScheduleLock::TTL_SECONDS - 1);
        self::assertFalse($this->aNewWorkerCanTakeTheLock());

        // Symfony's Checkpoint asked for the minute until the next run on top: the lock would have lived until 10:02.
        $this->clock->sleep(2);
        $nextLock = new DefaultScheduleLock($this->lockFactory);
        self::assertSame(1, $this->tick($this->newGenerator($nextLock), $nextLock));
        self::assertSame(0, $this->tick($killedWorker, $killedLock));
    }

    #[Test]
    public function aWorkerThatKeepsTickingKeepsTheScheduleFromASecondWorker(): void
    {
        $firstLock = new DefaultScheduleLock($this->lockFactory);
        $firstWorker = $this->newGenerator($firstLock);
        $secondLock = new DefaultScheduleLock($this->lockFactory);
        $secondWorker = $this->newGenerator($secondLock);
        $runs = ['first' => $this->tick($firstWorker, $firstLock), 'second' => 0];

        // The second worker ticks first, so it would win each run the first worker's lock no longer covers.
        for ($second = 10; $second <= 300; $second += 10) {
            $this->clock->sleep(10);
            $runs['second'] += $this->tick($secondWorker, $secondLock);
            $runs['first'] += $this->tick($firstWorker, $firstLock);
        }

        self::assertSame(['first' => 5, 'second' => 0], $runs);
    }

    #[Test]
    public function aStoppingWorkerFreesTheLockAtOnce(): void
    {
        $stoppingLock = new DefaultScheduleLock($this->lockFactory);
        $this->tick($this->newGenerator($stoppingLock), $stoppingLock);
        self::assertFalse($this->aNewWorkerCanTakeTheLock());

        $stoppingLock->releaseWhenWorkerStops(new WorkerStoppedEvent($this->worker));

        self::assertTrue($this->aNewWorkerCanTakeTheLock());
    }

    #[Test]
    public function aWorkerThatLostItsLockInALongJobNeverTakesItBackByRenewing(): void
    {
        $stalledLock = new DefaultScheduleLock($this->lockFactory);
        self::runDueJobs($this->newGenerator($stalledLock));

        $this->clock->sleep(DefaultScheduleLock::TTL_SECONDS + 1);
        $takeoverLock = new DefaultScheduleLock($this->lockFactory);
        self::assertSame(1, $this->tick($this->newGenerator($takeoverLock), $takeoverLock));

        // The stalled worker's turn ends only now, while the other worker holds the lock: it learns it lost it.
        $stalledLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, false));
        $takeoverLock->releaseWhenWorkerStops(new WorkerStoppedEvent($this->worker));
        $stalledLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, true));

        self::assertTrue($this->aNewWorkerCanTakeTheLock());
    }

    #[Test]
    public function aStalledWorkerNobodyTookOverFromGivesUpTheLapsedLockAndWinsItBackOnItsNextDueTick(): void
    {
        $stalledLock = new DefaultScheduleLock($this->lockFactory);
        $stalledWorker = $this->newGenerator($stalledLock);
        self::runDueJobs($stalledWorker);

        $this->clock->sleep(DefaultScheduleLock::TTL_SECONDS + 1);
        // Its turn ends after the lock lapsed: renewing must not quietly take the lapsed lock back.
        $stalledLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, false));
        self::assertTrue($this->aNewWorkerCanTakeTheLock());

        self::assertSame(1, $this->tick($stalledWorker, $stalledLock));
        self::assertFalse($this->aNewWorkerCanTakeTheLock());
    }

    #[Test]
    public function aStoreErrorWhileRenewingLeavesTheWorkerRunningAndNoLongerTheHolder(): void
    {
        $store = new class(new ClockedLockStore($this->clock)) implements PersistingStoreInterface {
            public bool $isDown = false;
            public int $renewalAttempts = 0;

            public function __construct(private readonly ClockedLockStore $store)
            {
            }

            public function save(Key $key): void
            {
                $this->store->save($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                ++$this->renewalAttempts;
                if ($this->isDown) {
                    throw new \RuntimeException('Connection lost to redis://:state-secret@valkey:6379', 111);
                }
                $this->store->putOffExpiration($key, $ttl);
            }

            public function delete(Key $key): void
            {
                $this->store->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->store->exists($key);
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->lockFactory = new LockFactory($store);
        $holderLock = new DefaultScheduleLock($this->lockFactory, $logger);
        $holder = $this->newGenerator($holderLock);
        $this->tick($holder, $holderLock);

        $store->isDown = true;
        $this->clock->sleep(1);
        $attemptsBeforeOutage = $store->renewalAttempts;
        $holderLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, true));
        $this->clock->sleep(1);
        $holderLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, true));

        self::assertSame($attemptsBeforeOutage + 1, $store->renewalAttempts, 'a worker that is no longer the holder still renews');
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame(['exception_class' => \RuntimeException::class, 'code' => 111], $logger->records[0]['context']);
        self::assertStringNotContainsString('state-secret', $logger->records[0]['message']);

        $store->isDown = false;
        $this->clock->sleep(DefaultScheduleLock::TTL_SECONDS);
        self::assertSame(1, $this->tick($holder, $holderLock));
        self::assertFalse($this->aNewWorkerCanTakeTheLock());
    }

    #[Test]
    public function aWorkerThatNeverTookTheLockLeavesItAlone(): void
    {
        $otherWorkersLock = new DefaultScheduleLock($this->lockFactory);

        $otherWorkersLock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, true));

        self::assertTrue($this->aNewWorkerCanTakeTheLock());
    }

    private function newGenerator(DefaultScheduleLock $lock): MessageGenerator
    {
        $schedule = new DefaultSchedule($this->state, $lock)->getSchedule()
            ->add(RecurringMessage::every('1 minute', new \stdClass()));

        return new MessageGenerator($schedule, 'default', $this->clock);
    }

    /**
     * One turn of a worker's loop: the jobs it ran, then the WorkerRunningEvent the worker dispatches after each turn.
     */
    private function tick(MessageGenerator $generator, DefaultScheduleLock $lock): int
    {
        $ranJobs = self::runDueJobs($generator);
        $lock->renewWhileWorkerRuns(new WorkerRunningEvent($this->worker, 0 === $ranJobs));

        return $ranJobs;
    }

    private static function runDueJobs(MessageGenerator $generator): int
    {
        $ranJobs = 0;
        foreach ($generator->getMessages() as $ignored) {
            ++$ranJobs;
        }

        return $ranJobs;
    }

    private function aNewWorkerCanTakeTheLock(): bool
    {
        $probe = new DefaultScheduleLock($this->lockFactory);
        if (!$probe->acquire()) {
            return false;
        }
        $probe->release();

        return true;
    }
}
