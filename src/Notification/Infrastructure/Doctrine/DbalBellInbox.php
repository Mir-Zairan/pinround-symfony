<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\Notification\Application\Bell\BellKind;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * BellInbox on ntf_notification through the bell index, under row-level security and with the tenant's
 * organization_id named as well. The open-row filter is the one DbalBellQueries groups.
 */
final readonly class DbalBellInbox implements BellInbox
{
    private const string OPEN_ROWS = 'organization_id = :organization AND user_id = :user AND done_at IS NULL'
        .' AND actor_id IS NOT NULL AND ticket_id IS NOT NULL AND kind IN (:kinds)'
        .' AND (deliver_after IS NULL OR deliver_after <= :now)';

    private const string GROUP = ' AND actor_id = :sender AND ticket_id = :ticket';

    private const array TYPES = ['kinds' => ArrayParameterType::STRING];

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private BellCountCache $countCache)
    {
    }

    public function openCountOf(UserId $userId, \DateTimeImmutable $now): int
    {
        $parameters = $this->openRowParameters($userId, $now);

        return $this->countCache->remember($userId, function () use ($parameters): int {
            $count = $this->connection->fetchOne('SELECT count(*) FROM ntf_notification WHERE '.self::OPEN_ROWS, $parameters, self::TYPES);
            \assert(\is_int($count));

            return $count;
        });
    }

    public function markGroupDone(BellGroupKey $group, \DateTimeImmutable $now): int
    {
        $this->requireTransaction();
        $marked = (int) $this->connection->executeStatement(
            'UPDATE ntf_notification SET done_at = :now WHERE '.self::OPEN_ROWS.self::GROUP,
            $this->groupParameters($group, $now),
            self::TYPES,
        );
        if ($marked > 0) {
            $this->countCache->invalidateAfterCommit($group->recipientId);
        }

        return $marked;
    }

    public function markGroupRead(BellGroupKey $group, \DateTimeImmutable $now): ?string
    {
        $this->requireTransaction();
        $ticketKeys = $this->connection->fetchFirstColumn(
            'UPDATE ntf_notification SET read_at = COALESCE(read_at, :now) WHERE '.self::OPEN_ROWS.self::GROUP
            ." RETURNING data->>'ticketKey'",
            $this->groupParameters($group, $now),
            self::TYPES,
        );
        foreach ($ticketKeys as $ticketKey) {
            if (\is_string($ticketKey) && '' !== $ticketKey) {
                return $ticketKey;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function openRowParameters(UserId $userId, \DateTimeImmutable $now): array
    {
        return [
            'organization' => $this->tenantContext->require()->toString(),
            'user' => $userId->toString(),
            'kinds' => BellKind::codes(),
            'now' => $now->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupParameters(BellGroupKey $group, \DateTimeImmutable $now): array
    {
        return [
            ...$this->openRowParameters($group->recipientId, $now),
            'sender' => $group->senderId->toString(),
            'ticket' => $group->ticketId->toString(),
        ];
    }

    /**
     * TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the rows anyway,
     * and the count's invalidation needs the commit to wait for.
     */
    private function requireTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A bell group is changed in the request transaction; none is open.');
        }
    }
}
