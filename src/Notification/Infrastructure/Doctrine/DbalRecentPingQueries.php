<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Ping\RecentPingQueries;
use App\Notification\Application\Ping\RecentPings;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Reads ntf_ping through its index (organization_id, from_id, to_id, ticket_id, created_at). A ping cleared since still
 * counts: clearing it does not give the sender their slot back, as the ping window holds it all the same.
 */
final readonly class DbalRecentPingQueries implements RecentPingQueries
{
    private const string NEWEST_PINGS = <<<'SQL'
        SELECT to_id, max(created_at) AS newest_at
        FROM ntf_ping
        WHERE organization_id = :organization AND from_id = :from AND ticket_id = :ticket AND created_at > :since
        GROUP BY to_id
        SQL;

    public function __construct(private Connection $connection, private PingSettingsQueries $pingSettingsQueries)
    {
    }

    public function recentPingsOf(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId, \DateTimeImmutable $at): RecentPings
    {
        $limitHours = $this->pingSettingsQueries->pingLimitHoursOf($organizationId);
        $limit = new \DateInterval(\sprintf('PT%dH', $limitHours));
        $rows = $this->connection->fetchAllAssociative(self::NEWEST_PINGS, [
            'organization' => $organizationId->toString(),
            'from' => $fromUserId->toString(),
            'ticket' => $ticketId->toString(),
            'since' => $at->sub($limit)->format(\DateTimeInterface::ATOM),
        ]);

        $againAtById = [];
        foreach ($rows as ['to_id' => $toId, 'newest_at' => $newestAt]) {
            \assert(\is_string($toId) && \is_string($newestAt));
            $againAtById[new UserId($toId)->toString()] = new \DateTimeImmutable($newestAt)->add($limit);
        }

        return new RecentPings($limitHours, $againAtById);
    }
}
