<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Application\Notify\Notifications;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;

/**
 * Notifications on ntf_notification. pinround_app may only select, insert and update it; the row-level security policy
 * refuses a row of another organization than the transaction's tenant.
 */
final readonly class DbalNotifications implements Notifications
{
    private const string INSERT = 'INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data,'
        .' deliver_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

    // The last parameter is the shown reply's created_at, or null for a held reply, which then folds only into a row
    // held until the same time: a row whose hold ended before the release cleared its deliver_after is shown already.
    private const string FOLD = 'UPDATE ntf_notification SET actor_id = ?, data = ?, created_at = ?'
        ." WHERE organization_id = ? AND user_id = ? AND kind = ? AND data->>'messageId' = ?"
        .' AND read_at IS NULL AND done_at IS NULL AND created_at >= ?'
        .' AND (deliver_after IS NOT DISTINCT FROM ? OR deliver_after <= ?)';

    // No index covers user and ticket together: the bell index on (organization_id, user_id, actor_id, ticket_id,
    // created_at) WHERE done_at IS NULL narrows by its (organization_id, user_id) prefix to the person's open rows, or
    // ntf_notification_ticket_id_idx to the ticket's rows, and the rest of the WHERE filters those.
    private const string MARK_PINGS_DONE = 'UPDATE ntf_notification SET done_at = ?'
        .' WHERE organization_id = ? AND user_id = ? AND ticket_id = ? AND kind = ? AND done_at IS NULL';

    // RETURNING gives one user id per released row; the outer SELECT keeps each person once, in user id order.
    private const string RELEASE_DUE = 'WITH released AS ('
        .'UPDATE ntf_notification SET deliver_after = NULL WHERE organization_id = ? AND deliver_after <= ? RETURNING user_id'
        .') SELECT DISTINCT user_id FROM released ORDER BY user_id';

    // ntf_notification_ticket_id_idx narrows to the ticket's rows; the rest of the WHERE filters those.
    private const string MARK_DONE_ABOUT_MESSAGE = 'WITH marked AS ('
        .'UPDATE ntf_notification SET done_at = :at WHERE organization_id = :organization AND ticket_id = :ticket'
        ." AND kind IN (:kinds) AND data->>'messageId' = :message AND done_at IS NULL RETURNING user_id"
        .') SELECT DISTINCT user_id FROM marked ORDER BY user_id';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private BellCountCache $bellCountCache)
    {
    }

    public function add(NewNotification $notification): void
    {
        $this->requireTransaction();

        // The id carries the instant the row was written; a fold later moves created_at but keeps the id, so ordering by
        // id and by created_at can differ for a folded reply.
        $id = new UuidV7(UuidV7::generate($notification->createdAt));
        $this->connection->executeStatement(self::INSERT, [
            $notification->organizationId->toString(),
            $id->toRfc4122(),
            $notification->recipientId->toString(),
            $notification->kind->value,
            $notification->actorId->toString(),
            $notification->ticketId->toString(),
            self::jsonOf($notification->data),
            $notification->deliverAfter?->format(self::TIMESTAMP_FORMAT),
            $notification->createdAt->format(self::TIMESTAMP_FORMAT),
        ]);
        $this->bellCountCache->invalidateAfterCommit($notification->recipientId);
    }

    public function foldIntoUnreadReply(NewNotification $reply, \DateTimeImmutable $since): bool
    {
        $this->requireTransaction();
        $messageId = $reply->data['messageId'] ?? null;
        if (NotificationKind::Reply !== $reply->kind || null === $messageId) {
            throw new \LogicException('Only a reply notification about a message folds into another.');
        }

        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            \sprintf('ntf_reply:%s:%s:%s', $reply->organizationId->toString(), $reply->recipientId->toString(), $messageId),
        ]);

        $isFolded = 0 < $this->connection->executeStatement(self::FOLD, [
            $reply->actorId->toString(),
            self::jsonOf($reply->data),
            $reply->createdAt->format(self::TIMESTAMP_FORMAT),
            $reply->organizationId->toString(),
            $reply->recipientId->toString(),
            NotificationKind::Reply->value,
            $messageId,
            $since->format(self::TIMESTAMP_FORMAT),
            $reply->deliverAfter?->format(self::TIMESTAMP_FORMAT),
            null === $reply->deliverAfter ? $reply->createdAt->format(self::TIMESTAMP_FORMAT) : null,
        ]);
        if ($isFolded) {
            $this->bellCountCache->invalidateAfterCommit($reply->recipientId);
        }

        return $isFolded;
    }

    public function markPingsDone(OrganizationId $organizationId, UserId $recipientId, TicketId $ticketId, \DateTimeImmutable $at): int
    {
        $this->requireTransaction();

        $marked = (int) $this->connection->executeStatement(self::MARK_PINGS_DONE, [
            $at->format(self::TIMESTAMP_FORMAT),
            $organizationId->toString(),
            $recipientId->toString(),
            $ticketId->toString(),
            NotificationKind::Ping->value,
        ]);
        if ($marked > 0) {
            $this->bellCountCache->invalidateAfterCommit($recipientId);
        }

        return $marked;
    }

    public function releaseDue(OrganizationId $organizationId, \DateTimeImmutable $at): array
    {
        $this->requireTransaction();

        $recipientIds = self::recipientsOf($this->connection->fetchFirstColumn(self::RELEASE_DUE, [$organizationId->toString(), $at->format(self::TIMESTAMP_FORMAT)]));
        foreach ($recipientIds as $recipientId) {
            $this->bellCountCache->invalidateAfterCommit($recipientId);
        }

        return $recipientIds;
    }

    public function lockAboutMessage(OrganizationId $organizationId, string $messageId): void
    {
        $this->requireTransaction();

        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            \sprintf('ntf_message:%s:%s', $organizationId->toString(), $messageId),
        ]);
    }

    public function markDoneAboutMessage(OrganizationId $organizationId, TicketId $ticketId, string $messageId, array $kinds, \DateTimeImmutable $at): array
    {
        $this->lockAboutMessage($organizationId, $messageId);

        $recipientIds = self::recipientsOf($this->connection->fetchFirstColumn(
            self::MARK_DONE_ABOUT_MESSAGE,
            [
                'at' => $at->format(self::TIMESTAMP_FORMAT),
                'organization' => $organizationId->toString(),
                'ticket' => $ticketId->toString(),
                'kinds' => array_map(static fn (NotificationKind $kind): string => $kind->value, $kinds),
                'message' => $messageId,
            ],
            ['kinds' => ArrayParameterType::STRING],
        ));
        foreach ($recipientIds as $recipientId) {
            $this->bellCountCache->invalidateAfterCommit($recipientId);
        }

        return $recipientIds;
    }

    /**
     * @param list<mixed> $userIds
     *
     * @return list<UserId>
     */
    private static function recipientsOf(array $userIds): array
    {
        return array_map(static function (mixed $userId): UserId {
            if (!\is_string($userId)) {
                throw new \LogicException('An update of notifications returned a recipient that is not a uuid.');
            }

            return new UserId($userId);
        }, $userIds);
    }

    private function requireTransaction(): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the row anyway.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A notification is written in the transaction of the event it is about; none is open.');
        }
    }

    /**
     * @param array<string, string> $data
     */
    private static function jsonOf(array $data): string
    {
        // The object cast keeps an empty array a JSON object; the column must hold one.
        return json_encode((object) $data, \JSON_THROW_ON_ERROR);
    }
}
