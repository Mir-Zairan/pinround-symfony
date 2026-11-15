<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

/**
 * How long a lock of the lock store (the state Valkey, lock.yaml) still lives, read from the store itself, so a holder
 * in another process is seen too.
 */
interface LockLifetimes
{
    /**
     * Never throws: an unreachable store answers null, as a caller has to do without the answer anyway.
     *
     * @return positive-int|null milliseconds until the lock on $resource ends; null when nobody holds it, it never ends,
     *                           or the store cannot say
     */
    public function millisecondsLeftOf(string $resource): ?int;
}
