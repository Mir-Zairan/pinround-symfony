<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Refreshes the bell of the person whose ping was cleared, in worker-notify once the relayed event arrives, so their
 * other open pages drop the group too. A second delivery pushes the same count again, so it needs no once-only guard.
 */
final readonly class PushBellOnPingCleared
{
    public function __construct(private BellRefreshes $bellRefreshes)
    {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function __invoke(PingCleared $event): void
    {
        $this->bellRefreshes->sendOnceCommitted(new RefreshBell($event->organizationId, $event->toUserId, null), null);
    }
}
