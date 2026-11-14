<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Api\MessageView;
use App\Conversation\Api\ReplyView;
use App\Conversation\Api\ThreadQueries;
use App\Conversation\Api\ThreadView;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * ThreadQueries on cnv_message_ticket_id_created_at_id_idx (organization_id, ticket_id, created_at, id), read backwards,
 * then the replies of the listed messages on cnv_reply_message_id_created_at_id_idx.
 */
final readonly class DbalThreadQueries implements ThreadQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function threadOf(TicketId $ticketId): ThreadView
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A thread is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();

        // One row past the limit tells whether earlier messages were left out. The CASE keeps a removed message's
        // text in the database: its row still holds the body (cnv_message_body_check).
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_id, kind, CASE WHEN removed_at IS NULL THEN body END AS body, created_at, edited_at,'
            .' removed_at IS NOT NULL AS is_removed, to_user_id, resolved_at IS NOT NULL AS is_resolved'
            .' FROM cnv_message'
            .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = CAST(:ticket AS uuid)'
            .' ORDER BY created_at DESC, id DESC'
            .' LIMIT '.(ThreadView::MESSAGE_LIMIT + 1),
            [
                'organization' => $organizationId,
                'ticket' => $ticketId->toString(),
            ],
        );

        $hasEarlierMessages = \count($rows) > ThreadView::MESSAGE_LIMIT;
        $oldestFirst = array_reverse(\array_slice($rows, 0, ThreadView::MESSAGE_LIMIT));
        $replies = $this->repliesOf($organizationId, $oldestFirst);

        return new ThreadView(
            array_map(static fn (array $row): MessageView => self::messageOf($row, $replies), $oldestFirst),
            $hasEarlierMessages,
        );
    }

    /**
     * The listed replies of every message among the rows and how many each has, in one query; none when there are no
     * rows. A message without replies is absent from the result. Each message reads its newest replies backwards on
     * the index; the UNION adds the oldest live answer when it is older than those and drops it when it is one of
     * them. The CASE keeps a removed reply's text in the database.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{replies: list<ReplyView>, count: int<0, max>}> by message id
     */
    private function repliesOf(string $organizationId, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $messageIds = array_map(static fn (array $row): string => self::text($row['id'], 'id'), $rows);

        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $replyRows = $this->connection->fetchAllAssociative(
            'SELECT listed.message_id, counted.reply_count, reply.id, reply.author_id,'
            .' CASE WHEN reply.removed_at IS NULL THEN reply.body END AS body, reply.created_at, reply.is_answer,'
            .' reply.removed_at IS NOT NULL AS is_removed'
            .' FROM unnest(CAST(:messages AS uuid[])) AS listed (message_id)'
            .' CROSS JOIN LATERAL (SELECT count(*) AS reply_count FROM cnv_reply'
            .' WHERE organization_id = CAST(:organization AS uuid) AND message_id = listed.message_id) AS counted'
            .' JOIN LATERAL ('
            .'(SELECT id, author_id, body, created_at, is_answer, removed_at FROM cnv_reply'
            .' WHERE organization_id = CAST(:organization AS uuid) AND message_id = listed.message_id'
            .' ORDER BY created_at DESC, id DESC LIMIT '.MessageView::REPLY_LIMIT.')'
            .' UNION'
            .' (SELECT id, author_id, body, created_at, is_answer, removed_at FROM cnv_reply'
            .' WHERE organization_id = CAST(:organization AS uuid) AND message_id = listed.message_id'
            .' AND is_answer AND removed_at IS NULL'
            .' ORDER BY created_at, id LIMIT 1)'
            .') AS reply ON true'
            .' ORDER BY reply.created_at, reply.id',
            [
                'organization' => $organizationId,
                'messages' => '{'.implode(',', $messageIds).'}',
            ],
        );

        $replies = [];
        foreach ($replyRows as $replyRow) {
            $messageId = self::text($replyRow['message_id'], 'message_id');
            $replies[$messageId] ??= ['replies' => [], 'count' => self::count($replyRow['reply_count'], 'reply_count')];
            $replies[$messageId]['replies'][] = new ReplyView(
                self::text($replyRow['id'], 'id'),
                new UserId(self::text($replyRow['author_id'], 'author_id')),
                self::textOrNull($replyRow['body'], 'body'),
                new \DateTimeImmutable(self::text($replyRow['created_at'], 'created_at')),
                self::boolean($replyRow['is_answer'], 'is_answer'),
                self::boolean($replyRow['is_removed'], 'is_removed'),
            );
        }

        return $replies;
    }

    /**
     * @param array<string, mixed>                                               $row
     * @param array<string, array{replies: list<ReplyView>, count: int<0, max>}> $replies by message id
     */
    private static function messageOf(array $row, array $replies): MessageView
    {
        $id = self::text($row['id'], 'id');
        $createdAt = new \DateTimeImmutable(self::text($row['created_at'], 'created_at'));
        $editedAt = self::textOrNull($row['edited_at'], 'edited_at');
        $toUserId = self::textOrNull($row['to_user_id'], 'to_user_id');
        $isRemoved = self::boolean($row['is_removed'], 'is_removed');
        $isResolved = self::boolean($row['is_resolved'], 'is_resolved');
        $kind = MessageKind::from(self::text($row['kind'], 'kind'));
        $isQuestion = MessageKind::Question === $kind;
        $listedReplies = $replies[$id]['replies'] ?? [];
        $replyCount = $replies[$id]['count'] ?? 0;

        return new MessageView(
            $id,
            new UserId(self::text($row['author_id'], 'author_id')),
            match ($kind) {
                MessageKind::Comment => MessageView::KIND_COMMENT,
                MessageKind::Question => MessageView::KIND_QUESTION,
            },
            self::textOrNull($row['body'], 'body'),
            $createdAt,
            null === $editedAt ? null : new \DateTimeImmutable($editedAt),
            $isRemoved,
            $createdAt->modify(\sprintf('+%d minutes', Message::EDIT_WINDOW_MINUTES)),
            null === $toUserId ? null : new UserId($toUserId),
            $isQuestion && !$isResolved && !$isRemoved,
            $isQuestion && $isResolved,
            $listedReplies,
            $replyCount,
            $replyCount > \count($listedReplies),
            !$isQuestion && $isResolved,
        );
    }

    /**
     * @return int<0, max>
     */
    private static function count(mixed $value, string $column): int
    {
        if (\is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!\is_int($value) || $value < 0) {
            throw new \LogicException(\sprintf('A thread message %s from the database is not a count.', $column));
        }

        return $value;
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A thread message %s from the database is not a string.', $column));
        }

        return $value;
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function boolean(mixed $value, string $column): bool
    {
        if (!\is_bool($value)) {
            throw new \LogicException(\sprintf('A thread message %s from the database is not a boolean.', $column));
        }

        return $value;
    }
}
