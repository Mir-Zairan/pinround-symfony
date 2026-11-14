<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Realtime;

use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;

/**
 * Keeps what it is asked to send; whether a held one goes is MessengerBellRefreshes' call, proved in its test.
 */
final class BellRefreshesDouble implements BellRefreshes
{
    /** @var list<array{RefreshBell, \DateTimeImmutable|null}> */
    public array $sent = [];

    public function sendOnceCommitted(RefreshBell $refresh, ?\DateTimeImmutable $deliverAfter): void
    {
        $this->sent[] = [$refresh, $deliverAfter];
    }
}
