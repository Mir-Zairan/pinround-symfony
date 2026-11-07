<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\FixedWindowTryRefund;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * On in-memory counters and locks that write down, in order, every lock taken and released and every counter read
 * and written.
 */
final class FixedWindowTryRefundTest extends TestCase
{
    private const string LIMITER_ID = 'login_per_ip-abc';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $steps;

    private RecordingLimiterStorage $storage;

    private FixedWindowTryRefund $refund;

    private RateLimiterFactory $limiterFactory;

    protected function setUp(): void
    {
        $this->steps = new \ArrayObject();
        $this->storage = new RecordingLimiterStorage(new InMemoryStorage(), $this->steps);
        $lockFactory = new LockFactory(new RecordingLockStore(new InMemoryStore(), $this->steps));
        $this->refund = new FixedWindowTryRefund($this->storage, $lockFactory);
        $this->limiterFactory = new RateLimiterFactory(['id' => 'login_per_ip', 'policy' => 'fixed_window', 'limit' => 4, 'interval' => '60 minutes'], $this->storage, $lockFactory);
    }

    #[Test]
    public function itTakesOneTryOffTheLimitersCurrentWindow(): void
    {
        $limiter = $this->limiterFactory->create('abc');
        for ($try = 1; $try <= 4; ++$try) {
            $limiter->consume();
        }

        $this->refund->refundOneTry(self::LIMITER_ID);

        self::assertTrue($limiter->consume()->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
    }

    #[Test]
    public function itEditsTheCounterOnlyWhileHoldingTheLockTheLimiterTakes(): void
    {
        $this->limiterFactory->create('abc')->consume();
        $limiterSteps = $this->steps->getArrayCopy();
        $this->steps->exchangeArray([]);

        $this->refund->refundOneTry(self::LIMITER_ID);

        self::assertSame(['lock '.self::LIMITER_ID, 'fetch '.self::LIMITER_ID, 'save '.self::LIMITER_ID, 'unlock '.self::LIMITER_ID], $this->steps->getArrayCopy());
        self::assertSame($limiterSteps, $this->steps->getArrayCopy());
        self::assertSame(0, $this->hitCount());
    }

    #[Test]
    public function aTryTakenEarlierInTheCurrentWindowIsGivenBack(): void
    {
        $this->saveWindowWithThreeTriesStartedSecondsAgo(3500);

        $this->refund->refundOneTry(self::LIMITER_ID);

        self::assertSame(2, $this->hitCount());
    }

    #[Test]
    public function aTryTakenInAWindowThatHasEndedIsNotGivenBack(): void
    {
        $this->saveWindowWithThreeTriesStartedSecondsAgo(3700);

        $this->refund->refundOneTry(self::LIMITER_ID);

        self::assertSame(['lock '.self::LIMITER_ID, 'fetch '.self::LIMITER_ID, 'unlock '.self::LIMITER_ID], $this->steps->getArrayCopy());
        self::assertSame(3, $this->hitCount());
    }

    #[Test]
    public function withoutACounterNothingIsWritten(): void
    {
        $this->refund->refundOneTry(self::LIMITER_ID);

        self::assertSame(['lock '.self::LIMITER_ID, 'fetch '.self::LIMITER_ID, 'unlock '.self::LIMITER_ID], $this->steps->getArrayCopy());
        self::assertNull($this->storage->fetch(self::LIMITER_ID));
    }

    #[Test]
    public function aCounterThatCannotBeReadFailsTheRefundAndFreesTheLock(): void
    {
        $unreachable = new class implements StorageInterface {
            public function save(LimiterStateInterface $limiterState): void
            {
                throw new \RedisException('Connection refused');
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                throw new \RedisException('Connection refused');
            }

            public function delete(string $limiterStateId): void
            {
                throw new \RedisException('Connection refused');
            }
        };
        $lockFactory = new LockFactory(new RecordingLockStore(new InMemoryStore(), $this->steps));

        try {
            new FixedWindowTryRefund($unreachable, $lockFactory)->refundOneTry(self::LIMITER_ID);
            self::fail('The refund ignored a counter it could not read.');
        } catch (\RedisException) {
        }

        self::assertSame(['lock '.self::LIMITER_ID, 'unlock '.self::LIMITER_ID], $this->steps->getArrayCopy());
    }

    /**
     * A one-hour window of 4 tries, saved without a step in the log.
     */
    private function saveWindowWithThreeTriesStartedSecondsAgo(int $seconds): void
    {
        $windowStart = microtime(true) - $seconds;
        $window = new Window(self::LIMITER_ID, 3600, 4, $windowStart);
        $window->add(3, $windowStart);
        $this->storage->save($window);
        $this->steps->exchangeArray([]);
    }

    private function hitCount(): int
    {
        $window = $this->storage->fetch(self::LIMITER_ID);
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }
}
