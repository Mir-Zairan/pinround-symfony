<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\MessageKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\OpenQuestion;
use App\Ticketing\Api\OpenQuestionQueries;
use Doctrine\DBAL\Connection;

/**
 * Reads cnv_message through DBAL on cnv_message_ticket_id_open_question_key (organization_id, ticket_id), whose
 * predicate the WHERE repeats, so each ticket costs one probe of the partial index.
 */
final readonly class DbalOpenQuestionQueries implements OpenQuestionQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function openQuestionsTo(UserId $askedId, array $ticketIds): array
    {
        if (\count($ticketIds) > self::MAX_TICKET_IDS) {
            throw new \InvalidArgumentException(\sprintf('Open questions are read for at most %d tickets at a time.', self::MAX_TICKET_IDS));
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Open questions are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if ([] === $ticketIds) {
            return [];
        }

        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a TicketId holds no comma or brace.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ticket_id, author_id, body FROM cnv_message'
            .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = ANY(CAST(:tickets AS uuid[]))'
            .' AND kind = :question AND resolved_at IS NULL AND removed_at IS NULL AND to_user_id = CAST(:asked AS uuid)',
            [
                'organization' => $organizationId,
                'tickets' => '{'.implode(',', array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds)).'}',
                'question' => MessageKind::Question->value,
                'asked' => $askedId->toString(),
            ],
        );

        $questions = [];
        foreach ($rows as $row) {
            $questions[self::text($row['ticket_id'], 'ticket_id')] = new OpenQuestion(new UserId(self::text($row['author_id'], 'author_id')), self::text($row['body'], 'body'));
        }

        return $questions;
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('An open question %s from the database is not a string.', $column));
    }
}
