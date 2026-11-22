<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\CheckItemState;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\PinProgress;
use App\Ticketing\Api\PinProgressQueries;
use Doctrine\DBAL\Connection;

/**
 * Counts rvw_check_item per round through DBAL: the rounds by rvw_round_ticket_id_number_key (organization_id,
 * ticket_id, number), their items by rvw_check_item_round_id_idx; every join names organization_id as well as
 * row-level security.
 */
final readonly class DbalPinProgressQueries implements PinProgressQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function pinProgressOf(array $ticketIds): array
    {
        if (\count($ticketIds) > self::MAX_TICKET_IDS) {
            throw new \InvalidArgumentException(\sprintf('Pin progress is read for at most %d tickets at a time.', self::MAX_TICKET_IDS));
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Pin progress is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if ([] === $ticketIds) {
            return [];
        }

        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a TicketId holds no comma or brace. DISTINCT
        // ON keeps each ticket's shared round with the highest number, the checklist, which has none, below every other.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (round.ticket_id) round.ticket_id,'
            .' count(*) FILTER (WHERE item.state = :ok) AS ok_count, count(*) AS pin_count'
            .' FROM rvw_round AS round'
            .' JOIN rvw_check_item AS item ON item.organization_id = round.organization_id AND item.round_id = round.id'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = ANY(CAST(:tickets AS uuid[]))'
            .' AND round.kind IN (:checklist, :findings) AND round.state = :open AND round.shared_at IS NOT NULL'
            .' GROUP BY round.ticket_id, round.id, round.number'
            .' ORDER BY round.ticket_id, round.number DESC NULLS LAST',
            [
                'organization' => $organizationId,
                'tickets' => '{'.implode(',', array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds)).'}',
                'ok' => CheckItemState::Ok->value,
                'checklist' => RoundKind::Checklist->value,
                'findings' => RoundKind::Draft->value,
                'open' => RoundState::Open->value,
            ],
        );

        $progress = [];
        foreach ($rows as $row) {
            $okCount = self::count($row['ok_count'], 'ok_count');
            $pinCount = self::count($row['pin_count'], 'pin_count');
            if (0 === $pinCount) {
                throw new \LogicException('A pin progress from the database has no pins.');
            }
            $progress[self::text($row['ticket_id'], 'ticket_id')] = new PinProgress($okCount, $pinCount);
        }

        return $progress;
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
            throw new \LogicException(\sprintf('A pin progress %s from the database is not a count.', $column));
        }

        return $value;
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A pin progress %s from the database is not a string.', $column));
    }
}
