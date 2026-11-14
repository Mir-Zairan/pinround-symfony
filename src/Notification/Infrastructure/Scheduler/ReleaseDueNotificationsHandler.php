<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Releases an organization's notifications held until now or earlier, in worker-notify inside the bus transaction
 * under the message's TenantStamp: sets their deliver_after to NULL, then drops each recipient's cached count and
 * pushes their bell once the transaction commits. One statement both releases and returns the recipients, so of two
 * releases at once only the first finds a row, and a release delivered again pushes nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReleaseDueNotificationsHandler
{
    private const string RELEASE = 'WITH released AS ('
        .'UPDATE ntf_notification SET deliver_after = NULL WHERE organization_id = ? AND deliver_after <= ? RETURNING user_id'
        .') SELECT DISTINCT user_id FROM released ORDER BY user_id';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private BellCountCache $bellCountCache,
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

        $recipients = $this->connection->fetchFirstColumn(
            self::RELEASE,
            [$organizationId->toString(), $this->clock->now()->format('Y-m-d H:i:sP')],
        );
        foreach ($recipients as $recipient) {
            if (!\is_string($recipient)) {
                throw new \LogicException('The release returned a recipient that is not a uuid.');
            }
            $recipientId = new UserId($recipient);
            $this->bellCountCache->invalidateAfterCommit($recipientId);
            $this->bellRefreshes->sendOnceCommitted(new RefreshBell($organizationId->toString(), $recipientId->toString(), null), null);
        }
    }
}
