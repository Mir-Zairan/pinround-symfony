<?php

declare(strict_types=1);

namespace App\Notification\Application\Realtime;

interface BellRefreshes
{
    /**
     * Sends $refresh to worker-notify once the message being handled has committed, and drops it when that rolls back,
     * so the push reads the committed count and a retried message pushes once. Sends nothing while $deliverAfter is
     * after now: the notification is held for quiet hours, and whoever releases it sends the refresh then.
     */
    public function sendOnceCommitted(RefreshBell $refresh, ?\DateTimeImmutable $deliverAfter): void;
}
