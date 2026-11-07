<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Writes "lock <resource>" and "unlock <resource>" to the shared step log, then passes the call on.
 */
final readonly class RecordingLockStore implements PersistingStoreInterface
{
    /**
     * @param \ArrayObject<int, string> $steps
     */
    public function __construct(private PersistingStoreInterface $store, private \ArrayObject $steps)
    {
    }

    public function save(Key $key): void
    {
        $this->steps->append('lock '.$key);
        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->steps->append('unlock '.$key);
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
