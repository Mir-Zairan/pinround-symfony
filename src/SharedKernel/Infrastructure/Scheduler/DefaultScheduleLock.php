<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * The lock that lets one scheduler worker at a time run the `default` schedule. It outlives its worker's last tick by
 * TTL_SECONDS at most: each tick renews it and a stopping worker frees it. The image has no pcntl, so a SIGTERM (a
 * container restart) ends PHP before any destructor could free it; the next worker then waits TTL_SECONDS at most.
 *
 * Symfony's Checkpoint::release() asks for the time until the next run on top of the lock's lifetime, which kept a dead
 * worker's lock until its next run plus 300 s; refresh() keeps TTL_SECONDS instead. A lock lost to another worker (this
 * one stalled past TTL_SECONDS in a job) is not an error: the next due tick's acquire() decides who runs.
 *
 * Not reset between messages (no ResetInterface): the worker keeps the lock for its whole life.
 */
final class DefaultScheduleLock implements LockInterface
{
    public const string NAME = 'scheduler_default';

    /** Also the longest a job handled in the scheduler worker itself may run before a second worker could take over. */
    public const float TTL_SECONDS = 60.0;

    private readonly LockInterface $lock;
    private bool $isHeld = false;

    public function __construct(#[Autowire(service: 'lock.factory')] LockFactory $lockFactory)
    {
        $this->lock = $lockFactory->createLock(self::NAME, self::TTL_SECONDS);
    }

    public function acquire(bool $blocking = false): bool
    {
        return $this->isHeld = $this->lock->acquire($blocking);
    }

    /** Ignores $ttl: the lock always lives TTL_SECONDS from now. */
    public function refresh(?float $ttl = null): void
    {
        try {
            $this->lock->refresh(self::TTL_SECONDS);
        } catch (LockConflictedException) {
            $this->isHeld = false;
        }
    }

    public function isAcquired(): bool
    {
        return $this->isHeld = $this->lock->isAcquired();
    }

    public function release(): void
    {
        $this->isHeld = false;
        $this->lock->release();
    }

    public function isExpired(): bool
    {
        return $this->lock->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }

    /** Every messenger worker ticks; only the one holding the lock touches the store. */
    #[AsEventListener]
    public function renewWhileWorkerRuns(WorkerRunningEvent $event): void
    {
        if ($this->isHeld) {
            $this->refresh();
        }
    }

    #[AsEventListener]
    public function releaseWhenWorkerStops(WorkerStoppedEvent $event): void
    {
        if ($this->isHeld) {
            $this->release();
        }
    }
}
