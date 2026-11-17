<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Application\TicketHistoryEntries;
use App\Ticketing\Application\TicketHistoryEntry;
use Doctrine\DBAL\Connection;

/**
 * Reads through tkt_ticket_event's index on (organization_id, ticket_id, at DESC). The bound on the ticket's creation
 * lets PostgreSQL skip every monthly partition before it, as the table is partitioned by at.
 */
final readonly class DbalTicketHistoryEntries implements TicketHistoryEntries
{
    public const string LATEST_SQL = "SELECT id, actor_id, type, from_step, to_step, payload ->> 'verb' AS verb, payload ->> '".TicketHistory::GIVEN_TO_PAYLOAD_KEY."' AS given_to_id,"
        ." payload ->> 'outcome' AS outcome, payload ->> 'pin_number' AS pin_number, payload ->> 'area_name' AS area_name, at"
        .' FROM tkt_ticket_event'
        .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = CAST(:ticket AS uuid)'
        .' AND at >= CAST(:ticket_created_at AS timestamptz)'
        ." AND type IN ('".DbalTicketHistory::MOVED."', '".ReviewChange::CHECK_ITEM_CHANGED."', '".ReviewChange::PIN_VERDICT_SET."', '".ReviewChange::PIN_VERDICT_UNDONE."', '".ReviewChange::AREA_VERDICT_SAVED."')"
        .' ORDER BY at DESC, id DESC'
        .' LIMIT '.self::LATEST_COUNT;

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function latestOf(TicketId $ticketId, \DateTimeImmutable $ticketCreatedAt): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s history is read in a transaction under the organization as tenant; none is open.');
        }

        $rows = $this->connection->fetchAllAssociative(self::LATEST_SQL, self::parametersOf($this->tenantContext->require()->toString(), $ticketId, $ticketCreatedAt));

        return array_map(self::entryOf(...), $rows);
    }

    /**
     * @return array{organization: string, ticket: string, ticket_created_at: string}
     */
    public static function parametersOf(string $organizationId, TicketId $ticketId, \DateTimeImmutable $ticketCreatedAt): array
    {
        return [
            'organization' => $organizationId,
            'ticket' => $ticketId->toString(),
            // Microseconds kept: a move saved in the same second the ticket was created must not fall below the bound.
            'ticket_created_at' => $ticketCreatedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function entryOf(array $row): TicketHistoryEntry
    {
        if (!\is_string($row['actor_id']) || !\is_string($row['at'])) {
            throw new \UnexpectedValueException('A ticket event row lacks its actor or time.');
        }
        if (DbalTicketHistory::MOVED !== $row['type']) {
            return new TicketHistoryEntry(new UserId($row['actor_id']), null, null, null, null, new \DateTimeImmutable($row['at']), self::reviewChangeOf($row));
        }
        if (!\is_string($row['to_step'])) {
            throw new \UnexpectedValueException('A ticket move row lacks the step it entered.');
        }

        return new TicketHistoryEntry(
            new UserId($row['actor_id']),
            \is_string($row['from_step']) ? $row['from_step'] : null,
            $row['to_step'],
            \is_string($row['verb']) ? $row['verb'] : null,
            self::userIdOrNull($row['given_to_id']),
            new \DateTimeImmutable($row['at']),
        );
    }

    /**
     * A pin number or area name the payload lacks, or a pin number that is not a whole number from 1, reads as
     * unnamed.
     *
     * @param array<string, mixed> $row
     *
     * @throws \UnexpectedValueException when the row has no outcome, which RecordReviewChangesHandler always writes
     */
    private static function reviewChangeOf(array $row): ReviewChange
    {
        if (!\is_string($row['type']) || !\is_string($row['outcome']) || '' === $row['outcome']) {
            throw new \UnexpectedValueException('A ticket review change row lacks its outcome.');
        }
        $pinNumber = \is_string($row['pin_number']) && 1 === preg_match('/\A[1-9][0-9]{0,8}\z/', $row['pin_number']) ? (int) $row['pin_number'] : null;

        return new ReviewChange(
            $row['type'],
            $row['outcome'],
            $pinNumber,
            \is_string($row['area_name']) && '' !== $row['area_name'] ? $row['area_name'] : null,
        );
    }

    /**
     * A payload is JSON anyone's later code may have written, so an id that is not a user id reads as nobody known.
     */
    private static function userIdOrNull(mixed $value): ?UserId
    {
        if (!\is_string($value)) {
            return null;
        }
        try {
            return new UserId($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
