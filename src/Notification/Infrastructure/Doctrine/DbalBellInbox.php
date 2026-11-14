<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * BellInbox on ntf_notification through the bell index, under row-level security and with the tenant's
 * organization_id named as well. The open-row filter is the one DbalBellQueries groups, and a row counts only while
 * VisibleBellTickets finds its ticket visible to the person; MarkBellGroupDone and OpenBellGroup check the ticket
 * before a change.
 */
final readonly class DbalBellInbox implements BellInbox
{
    private const string GROUP = ' AND actor_id = :sender AND ticket_id = :ticket';

    private const string VISIBLE_TICKETS = ' AND ticket_id IN (:tickets)';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private BellCountCache $countCache,
        private VisibleBellTickets $visibleTickets,
    ) {
    }

    public function openCountOf(UserId $userId, \DateTimeImmutable $now): int
    {
        $parameters = $this->openRowParameters($userId, $now);

        return $this->countCache->remember($userId, function () use ($userId, $now, $parameters): int {
            $visibleTicketIds = $this->visibleTickets->amongOpenRowsOf($userId, $now);
            if ([] === $visibleTicketIds) {
                return 0;
            }
            $count = $this->connection->fetchOne(
                'SELECT count(*) FROM ntf_notification WHERE '.VisibleBellTickets::OPEN_ROWS.self::VISIBLE_TICKETS,
                [...$parameters, 'tickets' => array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $visibleTicketIds)],
                [...VisibleBellTickets::OPEN_ROW_TYPES, 'tickets' => ArrayParameterType::STRING],
            );
            \assert(\is_int($count));

            return $count;
        });
    }

    public function markGroupDone(BellGroupKey $group, \DateTimeImmutable $now): int
    {
        $this->requireTransaction();
        $marked = (int) $this->connection->executeStatement(
            'UPDATE ntf_notification SET done_at = :now WHERE '.VisibleBellTickets::OPEN_ROWS.self::GROUP,
            $this->groupParameters($group, $now),
            VisibleBellTickets::OPEN_ROW_TYPES,
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
            'UPDATE ntf_notification SET read_at = COALESCE(read_at, :now) WHERE '.VisibleBellTickets::OPEN_ROWS.self::GROUP
            ." RETURNING data->>'ticketKey'",
            $this->groupParameters($group, $now),
            VisibleBellTickets::OPEN_ROW_TYPES,
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
        return VisibleBellTickets::openRowParametersOf($this->tenantContext->require()->toString(), $userId, $now);
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
