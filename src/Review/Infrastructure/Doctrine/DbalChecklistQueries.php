<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Application\Checklist\CheckItemPicture;
use App\Review\Application\Checklist\RoundChecklist;
use App\Review\Application\Checklist\RoundChecklistQueries;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use Doctrine\DBAL\Connection;

/**
 * Checklists from rvw_round, rvw_check_item, rvw_pin and rvw_screenshot through DBAL, one query each. forTicket finds
 * the round through rvw_round_ticket_id_checklist_key and forRounds the rounds by primary key, and both their items
 * through (organization_id, round_id); every join names organization_id as well as row-level security, so it uses an
 * index that starts with it.
 */
final readonly class DbalChecklistQueries implements ChecklistQueries, RoundChecklistQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function accessFactsOf(string $itemId): ?CheckItemAccessFacts
    {
        $organizationId = $this->requireTransactionAndTenant();
        try {
            $id = new CheckItemId($itemId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $itemRow = $this->connection->fetchAssociative(
            'SELECT item.round_id, round.ticket_id, pin.number AS pin_number'
            .' FROM rvw_check_item AS item'
            .' JOIN rvw_round AS round ON round.organization_id = item.organization_id AND round.id = item.round_id'
            .' JOIN rvw_pin AS pin ON pin.organization_id = item.organization_id AND pin.id = item.pin_id'
            .' WHERE item.organization_id = ? AND item.id = ?',
            [$organizationId->toString(), $id->toString()],
        );
        if (false === $itemRow) {
            return null;
        }

        return new CheckItemAccessFacts(
            $organizationId,
            $id->toString(),
            self::text($itemRow['round_id'], 'round_id'),
            new TicketId(self::text($itemRow['ticket_id'], 'ticket_id')),
            self::integer($itemRow['pin_number'], 'pin number'),
        );
    }

    public function forTicket(TicketId $ticketId): ?ChecklistView
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();
        $itemRows = $this->connection->fetchAllAssociative(
            'SELECT round.id AS round_id, round.number AS round_number, item.id AS item_id, pin.number AS pin_number, pin.note, item.state'
            .' FROM rvw_round AS round'
            .' JOIN rvw_check_item AS item ON item.organization_id = round.organization_id AND item.round_id = round.id'
            .' JOIN rvw_pin AS pin ON pin.organization_id = item.organization_id AND pin.id = item.pin_id'
            .' JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = pin.organization_id AND screenshot.id = pin.screenshot_id'
            ." WHERE round.organization_id = ? AND round.ticket_id = ? AND round.kind = 'checklist'"
            .' ORDER BY screenshot.position, pin.number',
            [$organizationId, $ticketId->toString()],
        );
        if ([] === $itemRows) {
            return null;
        }

        return new ChecklistView(
            self::text($itemRows[0]['round_id'], 'round_id'),
            self::integer($itemRows[0]['round_number'], 'round number'),
            array_map(self::itemOf(...), $itemRows),
        );
    }

    public function forRounds(TicketId $ticketId, array $roundIds): array
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();
        $canonicalRoundIds = array_values(array_unique(array_map(static fn (string $roundId): string => new RoundId($roundId)->toString(), $roundIds)));
        if ([] === $canonicalRoundIds) {
            return [];
        }

        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; RoundId let through no comma or brace.
        $itemRows = $this->connection->fetchAllAssociative(
            'SELECT round.id AS round_id, round.number AS round_number, round.kind, item.id AS item_id, pin.number AS pin_number, pin.note, item.state,'
            .' screenshot.position AS picture_position, screenshot.title AS picture_title'
            .' FROM rvw_round AS round'
            .' JOIN rvw_check_item AS item ON item.organization_id = round.organization_id AND item.round_id = round.id'
            .' JOIN rvw_pin AS pin ON pin.organization_id = item.organization_id AND pin.id = item.pin_id'
            .' JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = pin.organization_id AND screenshot.id = pin.screenshot_id'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            .' AND round.id = ANY(CAST(:rounds AS uuid[])) AND round.kind IN (:checklist, :findings)'
            .' ORDER BY round.number, screenshot.position, pin.number',
            [
                'organization' => $organizationId,
                'ticket' => $ticketId->toString(),
                'rounds' => '{'.implode(',', $canonicalRoundIds).'}',
                'checklist' => RoundKind::Checklist->value,
                'findings' => RoundKind::Draft->value,
            ],
        );

        $itemsByRound = [];
        $picturesByRound = [];
        $roundRows = [];
        foreach ($itemRows as $itemRow) {
            $roundId = self::text($itemRow['round_id'], 'round_id');
            $roundRows[$roundId] ??= $itemRow;
            $item = self::itemOf($itemRow);
            $itemsByRound[$roundId][] = $item;
            $picturesByRound[$roundId][$item->itemId] = new CheckItemPicture(
                self::integer($itemRow['picture_position'], 'picture position'),
                null === $itemRow['picture_title'] ? null : self::text($itemRow['picture_title'], 'picture title'),
            );
        }

        $checklists = [];
        foreach ($itemsByRound as $roundId => $items) {
            $checklists[$roundId] = new RoundChecklist(
                new ChecklistView($roundId, self::integer($roundRows[$roundId]['round_number'], 'round number'), $items),
                RoundKind::Draft->value === $roundRows[$roundId]['kind'],
                $picturesByRound[$roundId],
            );
        }

        return $checklists;
    }

    /**
     * @param array<string, mixed> $itemRow
     */
    private static function itemOf(array $itemRow): CheckItemView
    {
        return new CheckItemView(
            self::text($itemRow['item_id'], 'item_id'),
            self::integer($itemRow['pin_number'], 'pin number'),
            self::text($itemRow['note'], 'note'),
            self::text($itemRow['state'], 'state'),
        );
    }

    private function requireTransactionAndTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Checklists are read in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A checklist %s from the database is not a string.', $column));
    }

    private static function integer(mixed $value, string $column): int
    {
        return \is_int($value) ? $value : throw new \LogicException(\sprintf('A checklist %s from the database is not an integer.', $column));
    }
}
