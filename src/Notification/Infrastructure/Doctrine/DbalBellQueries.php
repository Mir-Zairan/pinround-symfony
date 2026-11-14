<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Api\BellGroup;
use App\Notification\Api\BellView;
use App\Notification\Application\Bell\BellKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The bell in one query under row-level security: the person's open notifications are read through the bell index
 * (organization_id, user_id, actor_id, ticket_id, created_at DESC) WHERE done_at IS NULL, grouped by sender and
 * ticket with window functions, and each group's newest row is joined to its ping for the text. Names and keys come
 * from the snapshot the notification was written with, so no other module is asked. A notification held for quiet
 * hours (deliver_after still ahead), or one with no sender or ticket, is no bell item.
 */
final readonly class DbalBellQueries
{
    public const int MAX_BELL_GROUPS = 50;

    private const string BELL = <<<'SQL'
        SELECT newest.actor_id, newest.ticket_id, newest.kind, newest.created_at,
            newest.data->>'actorName' AS sender_name, newest.data->>'ticketKey' AS ticket_key,
            newest.group_size, newest.group_ping_count, newest.has_unread, newest.open_count, ping.body AS ping_text
        FROM (
            SELECT bell.actor_id, bell.ticket_id, bell.kind, bell.data, bell.created_at,
                count(*) OVER grp AS group_size,
                count(*) FILTER (WHERE bell.kind = 'ping') OVER grp AS group_ping_count,
                bool_or(bell.read_at IS NULL) OVER grp AS has_unread,
                count(*) OVER () AS open_count,
                row_number() OVER (grp ORDER BY bell.created_at DESC, bell.id DESC) AS position
            FROM ntf_notification AS bell
            WHERE bell.organization_id = :organization AND bell.user_id = :user AND bell.done_at IS NULL
                AND bell.actor_id IS NOT NULL AND bell.ticket_id IS NOT NULL AND bell.kind IN (:kinds)
                AND (bell.deliver_after IS NULL OR bell.deliver_after <= :now)
            WINDOW grp AS (PARTITION BY bell.actor_id, bell.ticket_id)
        ) AS newest
        LEFT JOIN ntf_ping AS ping ON newest.kind = 'ping' AND ping.organization_id = :organization
            AND ping.id = CAST(newest.data->>'pingId' AS uuid)
        WHERE newest.position = 1
        ORDER BY newest.group_size DESC, newest.created_at DESC, newest.ticket_id, newest.actor_id
        LIMIT :cap
        SQL;

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private ClockInterface $clock)
    {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \UnexpectedValueException                  when a notification's snapshot lacks the sender's name or the
     *                                                    ticket's key, which the handler that wrote it always sets
     */
    public function bell(UserId $userId): BellView
    {
        $rows = $this->connection->fetchAllAssociative(
            self::BELL,
            [
                'organization' => $this->tenantContext->require()->toString(),
                'user' => $userId->toString(),
                'kinds' => BellKind::codes(),
                'now' => $this->clock->now()->format('Y-m-d H:i:sP'),
                'cap' => self::MAX_BELL_GROUPS,
            ],
            ['kinds' => ArrayParameterType::STRING, 'cap' => ParameterType::INTEGER],
        );

        $openCount = 0;
        $groups = [];
        foreach ($rows as $row) {
            $openCount = self::intOf($row['open_count']);
            $groups[] = self::groupOf($row);
        }

        return new BellView($openCount, $groups);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function groupOf(array $row): BellGroup
    {
        $size = self::intOf($row['group_size']);
        \assert($size > 0);

        return new BellGroup(
            new UserId(self::stringOf($row['actor_id'])),
            self::snapshotOf($row['sender_name'], 'actorName'),
            new TicketId(self::stringOf($row['ticket_id'])),
            self::snapshotOf($row['ticket_key'], 'ticketKey'),
            $size,
            self::intOf($row['group_ping_count']) === $size,
            self::stringOf($row['kind']),
            \is_string($row['ping_text']) ? $row['ping_text'] : null,
            new \DateTimeImmutable(self::stringOf($row['created_at'])),
            true === $row['has_unread'],
        );
    }

    private static function snapshotOf(mixed $value, string $field): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException(\sprintf('A notification in the bell has no %s in its data.', $field));
        }

        return $value;
    }

    private static function stringOf(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }

    private static function intOf(mixed $value): int
    {
        \assert(\is_int($value));

        return $value;
    }
}
