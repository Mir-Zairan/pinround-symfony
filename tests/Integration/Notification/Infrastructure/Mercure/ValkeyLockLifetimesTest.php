<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Mercure;

use App\Notification\Infrastructure\Mercure\ValkeyLockLifetimes;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;

/**
 * On the state Valkey of the tests (TEST_STATE_VALKEY_DSN), with locks taken through the app's own lock factory, so a
 * change in how RedisStore keeps a lock shows here.
 */
final class ValkeyLockLifetimesTest extends KernelTestCase
{
    private const string WINDOW = 'realtime-refresh:0197a3c0-0000-7000-8000-00000000ff01';
    private const string KEY_THAT_NEVER_EXPIRES = 'realtime-refresh:0197a3c0-0000-7000-8000-00000000ff02';

    protected function setUp(): void
    {
        $this->forgetKeys();
    }

    protected function tearDown(): void
    {
        $this->forgetKeys();
        parent::tearDown();
    }

    #[Test]
    public function aHeldLockAnswersTheTimeItHasLeft(): void
    {
        $lock = $this->lockFactory()->createLock(self::WINDOW, 2.0, autoRelease: false);
        self::assertTrue($lock->acquire());

        $millisecondsLeft = $this->lockLifetimes()->millisecondsLeftOf(self::WINDOW);

        self::assertNotNull($millisecondsLeft);
        self::assertGreaterThan(1500, $millisecondsLeft);
        self::assertLessThanOrEqual(2000, $millisecondsLeft);
    }

    #[Test]
    public function aLockNobodyHoldsOrOneReleasedAnswersNull(): void
    {
        self::assertNull($this->lockLifetimes()->millisecondsLeftOf(self::WINDOW));

        $lock = $this->lockFactory()->createLock(self::WINDOW, 2.0, autoRelease: false);
        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertNull($this->lockLifetimes()->millisecondsLeftOf(self::WINDOW));
    }

    #[Test]
    public function aKeyThatNeverExpiresAnswersNull(): void
    {
        $this->stateValkey()->set(self::KEY_THAT_NEVER_EXPIRES, 'held');

        self::assertNull($this->lockLifetimes()->millisecondsLeftOf(self::KEY_THAT_NEVER_EXPIRES));
    }

    #[Test]
    public function anUnreachableValkeyAnswersNull(): void
    {
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 1]);
        self::assertInstanceOf(\Redis::class, $unreachable);

        self::assertNull(new ValkeyLockLifetimes($unreachable)->millisecondsLeftOf(self::WINDOW));
    }

    private function forgetKeys(): void
    {
        $this->stateValkey()->del(self::WINDOW, self::KEY_THAT_NEVER_EXPIRES);
    }

    private function lockLifetimes(): ValkeyLockLifetimes
    {
        return new ValkeyLockLifetimes($this->stateValkey());
    }

    private function lockFactory(): LockFactory
    {
        return self::getContainer()->get(LockFactory::class);
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }
}
