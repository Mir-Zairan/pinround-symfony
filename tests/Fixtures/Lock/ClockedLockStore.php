<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Lock;

use App\Notification\Infrastructure\Mercure\LockLifetimes;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A lock store that keeps its locks in memory and lets them expire by the given clock, as the RedisStore on the state
 * Valkey does by its key's time to live: one holder per resource name (case-sensitive, compared byte for byte), a
 * conflict while another holder's lock lives, a conflict on renewing a lock that is gone, and a lock nobody released is
 * free once its time is up. Symfony's InMemoryStore never expires a lock, so a window lock in it would never end. As the
 * state Valkey answers PTTL, it tells how long a lock has left, whoever holds it.
 */
final class ClockedLockStore implements PersistingStoreInterface, LockLifetimes
{
    /** As RedisStore: the lock is first saved for this long, then Lock::acquire() sets its time to live. */
    private const int INITIAL_TTL_MICROSECONDS = 300_000_000;

    /** @var array<string, array{token: string, expiresAtMicroseconds: int}> */
    private array $locks = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function save(Key $key): void
    {
        $this->hold($key, self::INITIAL_TTL_MICROSECONDS);
    }

    /** As RedisStore: only a holder whose lock still lives may renew it; a lapsed lock is not taken back. */
    public function putOffExpiration(Key $key, float $ttl): void
    {
        if (!$this->exists($key)) {
            throw new LockConflictedException();
        }
        $this->hold($key, (int) round($ttl * 1_000_000));
    }

    public function delete(Key $key): void
    {
        if ($this->exists($key)) {
            unset($this->locks[(string) $key]);
        }
    }

    public function exists(Key $key): bool
    {
        $lock = $this->locks[(string) $key] ?? null;

        return null !== $lock && $lock['token'] === self::tokenOf($key) && $lock['expiresAtMicroseconds'] > $this->nowMicroseconds();
    }

    public function millisecondsLeftOf(string $resource): ?int
    {
        $lock = $this->locks[$resource] ?? null;
        $microsecondsLeft = null === $lock ? 0 : $lock['expiresAtMicroseconds'] - $this->nowMicroseconds();

        // Valkey keeps expiry times in whole milliseconds; rounding up never answers a lock as ended before it is.
        return $microsecondsLeft > 0 ? max(1, (int) ceil($microsecondsLeft / 1000)) : null;
    }

    private function hold(Key $key, int $ttlMicroseconds): void
    {
        $lock = $this->locks[(string) $key] ?? null;
        if (null !== $lock && $lock['token'] !== self::tokenOf($key) && $lock['expiresAtMicroseconds'] > $this->nowMicroseconds()) {
            throw new LockConflictedException();
        }
        $this->locks[(string) $key] = ['token' => self::tokenOf($key), 'expiresAtMicroseconds' => $this->nowMicroseconds() + $ttlMicroseconds];
        // As RedisStore: the key then tells its holder how long it has left, which Symfony's Scheduler Checkpoint reads.
        $key->reduceLifetime($ttlMicroseconds / 1_000_000);
    }

    private function nowMicroseconds(): int
    {
        return (int) $this->clock->now()->format('Uu');
    }

    private static function tokenOf(Key $key): string
    {
        if (!$key->hasState(self::class)) {
            $key->setState(self::class, bin2hex(random_bytes(16)));
        }
        $token = $key->getState(self::class);
        \assert(\is_string($token));

        return $token;
    }
}
