<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Lock;

use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A lock store that keeps its locks in memory and lets them expire by the given clock, as the RedisStore on the state
 * Valkey does by its key's time to live: one holder per resource name (case-sensitive, compared byte for byte), a
 * conflict while another holder's lock lives, and a lock nobody released is free once its time is up. Symfony's
 * InMemoryStore never expires a lock, so a window lock in it would never end.
 */
final class ClockedLockStore implements PersistingStoreInterface
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

    public function putOffExpiration(Key $key, float $ttl): void
    {
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

    private function hold(Key $key, int $ttlMicroseconds): void
    {
        $lock = $this->locks[(string) $key] ?? null;
        if (null !== $lock && $lock['token'] !== self::tokenOf($key) && $lock['expiresAtMicroseconds'] > $this->nowMicroseconds()) {
            throw new LockConflictedException();
        }
        $this->locks[(string) $key] = ['token' => self::tokenOf($key), 'expiresAtMicroseconds' => $this->nowMicroseconds() + $ttlMicroseconds];
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
