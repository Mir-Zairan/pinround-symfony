<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Api\MessageView;
use App\Conversation\Api\ThreadQueries;
use App\Conversation\Api\ThreadView;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * ThreadQueries on cnv_message_ticket_id_created_at_id_idx (organization_id, ticket_id, created_at, id), read backwards.
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

        // One row past the limit tells whether earlier messages were left out. The CASE keeps a removed message's
        // text in the database: its row still holds the body (cnv_message_body_check).
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_id, kind, CASE WHEN removed_at IS NULL THEN body END AS body, created_at, edited_at,'
            .' removed_at IS NOT NULL AS is_removed'
            .' FROM cnv_message'
            .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = CAST(:ticket AS uuid)'
            .' ORDER BY created_at DESC, id DESC'
            .' LIMIT '.(ThreadView::MESSAGE_LIMIT + 1),
            [
                'organization' => $this->tenantContext->require()->toString(),
                'ticket' => $ticketId->toString(),
            ],
        );

        $hasEarlierMessages = \count($rows) > ThreadView::MESSAGE_LIMIT;
        $newestFirst = \array_slice($rows, 0, ThreadView::MESSAGE_LIMIT);

        return new ThreadView(
            array_map(self::messageOf(...), array_reverse($newestFirst)),
            $hasEarlierMessages,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function messageOf(array $row): MessageView
    {
        $createdAt = new \DateTimeImmutable(self::text($row['created_at'], 'created_at'));
        $editedAt = self::textOrNull($row['edited_at'], 'edited_at');
        $isRemoved = $row['is_removed'];
        if (!\is_bool($isRemoved)) {
            throw new \LogicException('A thread message is_removed from the database is not a boolean.');
        }

        return new MessageView(
            self::text($row['id'], 'id'),
            new UserId(self::text($row['author_id'], 'author_id')),
            match (MessageKind::from(self::text($row['kind'], 'kind'))) {
                MessageKind::Comment => MessageView::KIND_COMMENT,
                MessageKind::Question => MessageView::KIND_QUESTION,
            },
            self::textOrNull($row['body'], 'body'),
            $createdAt,
            null === $editedAt ? null : new \DateTimeImmutable($editedAt),
            $isRemoved,
            $createdAt->modify(\sprintf('+%d minutes', Message::EDIT_WINDOW_MINUTES)),
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
}
