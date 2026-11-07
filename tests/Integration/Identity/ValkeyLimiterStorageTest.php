<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Security\LimiterStateNotStored;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\RateLimiter\Policy\FixedWindowLimiter;
use Symfony\Component\RateLimiter\Policy\Window;

/**
 * On the state Valkey of the tests (TEST_STATE_VALKEY_DSN), through the connection the app uses.
 */
final class ValkeyLimiterStorageTest extends KernelTestCase
{
    #[Test]
    public function aSavedWindowIsFetchedBackUntilItIsDeleted(): void
    {
        $connection = $this->stateValkey();
        $storage = new ValkeyLimiterStorage($connection);
        $id = 'login_per_ip-'.bin2hex(random_bytes(8));
        $window = new Window($id, 900, 5);
        $window->add(2);

        $storage->save($window);
        $fetched = $storage->fetch($id);

        self::assertInstanceOf(Window::class, $fetched);
        self::assertSame(3, $fetched->getAvailableTokens(microtime(true)));
        self::assertSame(1, $connection->exists('login_throttling:'.sha1($id)));
        self::assertGreaterThan(0, $connection->ttl('login_throttling:'.sha1($id)));
        $storage->delete($id);
        self::assertNull($storage->fetch($id));
    }

    #[Test]
    public function aValueThatIsNotALimiterStateIsRefused(): void
    {
        $connection = $this->stateValkey();
        $id = 'login_per_ip-'.bin2hex(random_bytes(8));
        $connection->set('login_throttling:'.sha1($id), serialize(new \ArrayObject()), ['EX' => 60]);

        $this->expectException(LimiterStateNotStored::class);
        new ValkeyLimiterStorage($connection)->fetch($id);
    }

    /**
     * A peek writes nothing, so a storage that read an unreachable Valkey as "no counter yet" would let every try in.
     */
    #[Test]
    public function aPeekOnAnUnreachableValkeyFailsInsteadOfLettingTheTryThrough(): void
    {
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 1]);
        self::assertInstanceOf(\Redis::class, $unreachable);
        $limiter = new FixedWindowLimiter('login_per_ip-abc', 5, new \DateInterval('PT15M'), new ValkeyLimiterStorage($unreachable));

        $this->expectException(\Throwable::class);
        $limiter->consume(0);
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }
}
