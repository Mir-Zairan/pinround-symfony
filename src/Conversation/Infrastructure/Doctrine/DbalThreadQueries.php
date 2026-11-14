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
 * then the answers of the listed answered questions on cnv_reply_message_id_created_at_id_idx.
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
        $answers = $this->answersOf($organizationId, $oldestFirst);

        return new ThreadView(
            array_map(static fn (array $row): MessageView => self::messageOf($row, $answers), $oldestFirst),
            $hasEarlierMessages,
        );
    }

    /**
     * The answer of each answered question among the rows, in one query; none when no question is answered. Only an
     * answer closes a question, so a resolved question is an answered one.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, ReplyView> by question id
     */
    private function answersOf(string $organizationId, array $rows): array
    {
        $answeredQuestionIds = [];
        foreach ($rows as $row) {
            if (MessageKind::Question->value === $row['kind'] && true === $row['is_resolved']) {
                $answeredQuestionIds[] = self::text($row['id'], 'id');
            }
        }
        if ([] === $answeredQuestionIds) {
            return [];
        }

        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $replyRows = $this->connection->fetchAllAssociative(
            'SELECT message_id, id, author_id, body, created_at FROM cnv_reply'
            .' WHERE organization_id = CAST(:organization AS uuid) AND message_id = ANY(CAST(:questions AS uuid[]))'
            .' AND is_answer AND removed_at IS NULL'
            .' ORDER BY message_id, created_at, id',
            [
                'organization' => $organizationId,
                'questions' => '{'.implode(',', $answeredQuestionIds).'}',
            ],
        );

        $answers = [];
        foreach ($replyRows as $replyRow) {
            // Answer closes the question it answers, so a second answer cannot exist; the first one wins if it did.
            $answers[self::text($replyRow['message_id'], 'message_id')] ??= new ReplyView(
                self::text($replyRow['id'], 'id'),
                new UserId(self::text($replyRow['author_id'], 'author_id')),
                self::text($replyRow['body'], 'body'),
                new \DateTimeImmutable(self::text($replyRow['created_at'], 'created_at')),
            );
        }

        return $answers;
    }

    /**
     * @param array<string, mixed>     $row
     * @param array<string, ReplyView> $answers by question id
     */
    private static function messageOf(array $row, array $answers): MessageView
    {
        $id = self::text($row['id'], 'id');
        $createdAt = new \DateTimeImmutable(self::text($row['created_at'], 'created_at'));
        $editedAt = self::textOrNull($row['edited_at'], 'edited_at');
        $toUserId = self::textOrNull($row['to_user_id'], 'to_user_id');
        $isRemoved = self::boolean($row['is_removed'], 'is_removed');
        $isResolved = self::boolean($row['is_resolved'], 'is_resolved');
        $kind = MessageKind::from(self::text($row['kind'], 'kind'));
        $isQuestion = MessageKind::Question === $kind;

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
            $answers[$id] ?? null,
        );
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
