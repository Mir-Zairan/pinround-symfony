<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Api\BellView;
use App\Notification\Api\NotificationQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Reads under row-level security and names the tenant's organization_id as well, so every lookup uses a key that
 * starts with it.
 */
final readonly class DbalNotificationQueries implements NotificationQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext, private DbalBellQueries $bellQueries)
    {
    }

    public function bell(UserId $userId): BellView
    {
        return $this->bellQueries->bell($userId);
    }

    public function isMuted(UserId $userId, TicketId $ticketId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM ntf_mute WHERE organization_id = ? AND user_id = ? AND ticket_id = ?',
            [$this->tenantContext->require()->toString(), $userId->toString(), $ticketId->toString()],
        );
    }
}
