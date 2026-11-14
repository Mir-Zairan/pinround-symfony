<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Notify\Notifications;
use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Releases an organization's notifications held until now or earlier through Notifications::releaseDue, in
 * worker-notify inside the bus transaction under the message's TenantStamp, then pushes each recipient's bell once the
 * transaction commits. Of two releases at once only the first finds a row, so a release delivered again pushes nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReleaseDueNotificationsHandler
{
    public function __construct(
        private Notifications $notifications,
        private TenantContext $tenantContext,
        private BellRefreshes $bellRefreshes,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any write
     * @throws MessageForAnotherTenantRefused             when the release names another organization than the one set
     */
    public function __invoke(ReleaseDueNotifications $release): void
    {
        $organizationId = new OrganizationId($release->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        foreach ($this->notifications->releaseDue($organizationId, $this->clock->now()) as $recipientId) {
            $this->bellRefreshes->sendOnceCommitted(new RefreshBell($organizationId->toString(), $recipientId->toString(), null), null);
        }
    }
}
