<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use Symfony\Component\Cache\Exception\InvalidArgumentException as CacheConnectionFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symfony's RedisStore keeps a lock under its resource name and expires that key when the last holder's lock ends, so
 * the key's PTTL is the time the lock has left.
 */
final readonly class ValkeyLockLifetimes implements LockLifetimes
{
    public function __construct(#[Autowire(service: 'state_valkey.connection')] private \Redis $connection)
    {
    }

    public function millisecondsLeftOf(string $resource): ?int
    {
        try {
            $millisecondsLeft = $this->connection->pttl($resource);
        } catch (\RedisException|CacheConnectionFailed) {
            return null;
        }

        // PTTL answers -2 for no key and -1 for a key that never expires; phpredis answers false on a failed command.
        return \is_int($millisecondsLeft) && $millisecondsLeft > 0 ? $millisecondsLeft : null;
    }
}
