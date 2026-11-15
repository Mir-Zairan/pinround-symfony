<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Mute\Mutes;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Mutes on ntf_mute, which pinround_app may only select, insert and delete. Names the tenant's organization_id as well
 * as row-level security, so each statement uses the primary key (organization_id, user_id, ticket_id).
 */
final readonly class DbalMutes implements Mutes
{
    private const string MUTE = 'INSERT INTO ntf_mute (organization_id, user_id, ticket_id, created_at) VALUES (?, ?, ?, ?)'
        .' ON CONFLICT (organization_id, user_id, ticket_id) DO NOTHING';

    private const string UNMUTE = 'DELETE FROM ntf_mute WHERE organization_id = ? AND user_id = ? AND ticket_id = ?';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function mute(UserId $userId, TicketId $ticketId, \DateTimeImmutable $at): bool
    {
        $organizationId = $this->tenantContext->require();
        $this->requireTransaction();

        return 0 < $this->connection->executeStatement(self::MUTE, [
            $organizationId->toString(),
            $userId->toString(),
            $ticketId->toString(),
            $at->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    public function unmute(UserId $userId, TicketId $ticketId): bool
    {
        $organizationId = $this->tenantContext->require();
        $this->requireTransaction();

        return 0 < $this->connection->executeStatement(self::UNMUTE, [
            $organizationId->toString(),
            $userId->toString(),
            $ticketId->toString(),
        ]);
    }

    private function requireTransaction(): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one a DELETE would find no row and say so.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A mute changes in the transaction of the request; none is open.');
        }
    }
}
