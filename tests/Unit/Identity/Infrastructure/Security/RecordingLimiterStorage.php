<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Writes "fetch <id>", "save <id>" or "delete <id>" to the shared step log, then passes the call on.
 */
final readonly class RecordingLimiterStorage implements StorageInterface
{
    /**
     * @param \ArrayObject<int, string> $steps
     */
    public function __construct(private StorageInterface $storage, private \ArrayObject $steps)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->steps->append('save '.$limiterState->getId());
        $this->storage->save($limiterState);
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $this->steps->append('fetch '.$limiterStateId);

        return $this->storage->fetch($limiterStateId);
    }

    public function delete(string $limiterStateId): void
    {
        $this->steps->append('delete '.$limiterStateId);
        $this->storage->delete($limiterStateId);
    }
}
