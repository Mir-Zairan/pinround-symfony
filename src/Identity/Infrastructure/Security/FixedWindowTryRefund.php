<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Gives one try back to a fixed-window limiter of rate_limiter.yaml. Symfony's limiters have no refund, so this takes
 * the lock their consume() takes, with the same name, and edits the counter they keep in the same storage: a refund
 * and a parallel try never overwrite each other's count.
 */
final readonly class FixedWindowTryRefund
{
    public function __construct(
        #[Autowire(service: ValkeyLimiterStorage::class)]
        private StorageInterface $storage,
        #[Autowire(service: 'lock.factory')]
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Takes one try off the current window. Does nothing when the window the try was taken in has ended, as the new
     * window never counted it, or when there is no counter. Lets the storage's and the lock's exceptions through, so a
     * Valkey outage fails the request.
     *
     * @param string $limiterId the id RateLimiterFactory::create() gives the limiter: its name, "-", then its key
     */
    public function refundOneTry(string $limiterId): void
    {
        $lock = $this->lockFactory->createLock($limiterId);
        $lock->acquire(true);
        try {
            $window = $this->storage->fetch($limiterId);
            if (!$window instanceof Window) {
                return;
            }

            $now = microtime(true);
            $hitCount = $window->getHitCount();
            // Adding nothing starts the next window when this one has ended, which changes the count.
            $window->add(0, $now);
            if ($window->getHitCount() !== $hitCount) {
                return;
            }

            $window->add(-1, $now);
            $this->storage->save($window);
        } finally {
            $lock->release();
        }
    }
}
