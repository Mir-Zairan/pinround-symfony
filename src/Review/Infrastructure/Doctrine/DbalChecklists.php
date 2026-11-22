<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\Checklist;
use App\Review\Domain\Checklists;
use App\Review\Domain\FindingsChecklist;
use App\Review\Domain\PinId;
use App\Review\Domain\ReportPins;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Checklists on rvw_round and rvw_check_item through DBAL; no entity maps them (doctrine.yaml schema_filter). Adding
 * one takes two statements whatever its size: the round, then all its items; the items of a findings round take one.
 * A round holds at most Round::MAX_SCREENSHOTS times Screenshot::MAX_PINS pins, so the items bind at most 40,000
 * values, under PostgreSQL's 65,535. Reads name organization_id as well as row-level security, so each one uses an index that starts with it.
 */
final readonly class DbalChecklists implements Checklists
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    private const array ITEM_COLUMNS = ['organization_id', 'id', 'round_id', 'pin_id', 'state', 'changed_by', 'changed_at', 'created_at'];

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function nextItemIdentity(): CheckItemId
    {
        return new CheckItemId(Uuid::v7()->toRfc4122());
    }

    public function add(Checklist $checklist): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A checklist is added in a transaction under its organization as tenant; none is open.');
        }

        // A savepoint inside the caller's transaction, as in DbalRounds::add: a refused item leaves no round behind.
        $this->connection->transactional(function () use ($checklist): void {
            $organizationId = $checklist->organizationId()->toString();
            $roundId = $checklist->id()->toString();
            $createdAt = $checklist->createdAt()->format(self::TIMESTAMP);
            // DO NOTHING on any unique index: rvw_round_ticket_id_checklist_key is the slot a checklist takes, as it has
            // no number, and two requests that both found no checklist both get here.
            $inserted = $this->connection->executeStatement(
                'INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)'
                .' VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 1, ?) ON CONFLICT DO NOTHING',
                [
                    $organizationId,
                    $roundId,
                    $checklist->ticketId()->toString(),
                    RoundKind::Checklist->value,
                    $checklist->ownerId()->toString(),
                    $createdAt,
                    RoundState::Open->value,
                    $createdAt,
                ],
            );
            if (1 !== $inserted) {
                if (false !== $this->connection->fetchOne('SELECT 1 FROM rvw_round WHERE organization_id = ? AND id = ?', [$organizationId, $roundId])) {
                    throw new \LogicException(\sprintf('Round %s is added already; a round is added once.', $roundId));
                }

                throw new RoundSlotTaken('The ticket has this round already.');
            }

            // Item ids come from nextItemIdentity, the pins from reportPinsOf, and the ticket's checklist slot is taken
            // above, so a refused item is a bug.
            $this->insertItems($organizationId, $roundId, $createdAt, $checklist->items());
        });
    }

    public function addFindings(FindingsChecklist $checklist): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Findings are added in a transaction under their organization as tenant; none is open.');
        }

        // A savepoint, as in add(). The share saved before stays until the caller's transaction rolls back.
        $this->connection->transactional(function () use ($checklist): void {
            // The pins are the shared round's own, so a refused item is a second call for the round.
            $this->insertItems(
                $checklist->organizationId()->toString(),
                $checklist->roundId()->toString(),
                $checklist->createdAt()->format(self::TIMESTAMP),
                $checklist->items(),
            );
        });
    }

    /**
     * All the items in one statement, which a round's caps keep under PostgreSQL's 65,535 bound values.
     *
     * @param non-empty-list<CheckItem> $items
     *
     * @throws \LogicException when an item's id was added before, or its pin is not stored or is in a checklist already
     */
    private function insertItems(string $organizationId, string $roundId, string $createdAt, array $items): void
    {
        $itemRows = array_map(static fn (CheckItem $item): array => [
            $organizationId,
            $item->id()->toString(),
            $roundId,
            $item->pinId()->toString(),
            $item->state()->value,
            $item->changedBy()?->toString(),
            $item->changedAt()?->format(self::TIMESTAMP),
            $createdAt,
        ], $items);
        $rowPlaceholders = '('.implode(', ', array_fill(0, \count(self::ITEM_COLUMNS), '?')).')';
        try {
            $this->connection->executeStatement(
                \sprintf('INSERT INTO rvw_check_item (%s) VALUES %s', implode(', ', self::ITEM_COLUMNS), implode(', ', array_fill(0, \count($itemRows), $rowPlaceholders))),
                array_merge(...$itemRows),
            );
        } catch (ForeignKeyConstraintViolationException|UniqueConstraintViolationException $refusal) {
            throw new \LogicException('A checklist item has an id added before, or names a pin that is not stored or is in a checklist already.', 0, $refusal);
        }
    }

    public function reportPinsOf(TicketId $ticketId): ?ReportPins
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();
        // rvw_round_ticket_id_number_key holds at most one Round 0 of the ticket.
        $reportRow = $this->connection->fetchAssociative(
            'SELECT id, owner_id FROM rvw_round WHERE organization_id = ? AND ticket_id = ? AND number = 0',
            [$organizationId, $ticketId->toString()],
        );
        if (false === $reportRow) {
            return null;
        }
        $pinIds = $this->connection->fetchFirstColumn(
            'SELECT pin.id FROM rvw_pin AS pin JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = pin.organization_id AND screenshot.id = pin.screenshot_id'
            .' WHERE screenshot.organization_id = ? AND screenshot.round_id = ? ORDER BY screenshot.position, pin.number',
            [$organizationId, self::text($reportRow['id'], 'id')],
        );

        return new ReportPins(
            new UserId(self::text($reportRow['owner_id'], 'owner_id')),
            array_map(static fn (mixed $pinId): PinId => new PinId(self::text($pinId, 'pin id')), $pinIds),
        );
    }

    public function lockedItem(CheckItemId $id): ?CheckItem
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();
        // OF item: the round row stays free, so locking one item never holds up the others of its checklist.
        $itemRow = $this->connection->fetchAssociative(
            'SELECT item.round_id, item.pin_id, item.state, item.changed_by, item.changed_at, round.ticket_id'
            .' FROM rvw_check_item AS item JOIN rvw_round AS round ON round.organization_id = item.organization_id AND round.id = item.round_id'
            .' WHERE item.organization_id = ? AND item.id = ? FOR UPDATE OF item',
            [$organizationId, $id->toString()],
        );
        if (false === $itemRow) {
            return null;
        }
        $changedBy = $itemRow['changed_by'];
        $changedAt = $itemRow['changed_at'];

        return CheckItem::restored(
            $id,
            new RoundId(self::text($itemRow['round_id'], 'round_id')),
            new TicketId(self::text($itemRow['ticket_id'], 'ticket_id')),
            new PinId(self::text($itemRow['pin_id'], 'pin_id')),
            CheckItemState::from(self::text($itemRow['state'], 'state')),
            null === $changedBy ? null : new UserId(self::text($changedBy, 'changed_by')),
            null === $changedAt ? null : new \DateTimeImmutable(self::text($changedAt, 'changed_at')),
        );
    }

    public function saveItem(CheckItem $item): void
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();
        $updated = $this->connection->executeStatement(
            'UPDATE rvw_check_item SET state = ?, changed_by = ?, changed_at = ? WHERE organization_id = ? AND id = ? AND state = ?',
            [
                $item->state()->value,
                $item->changedBy()?->toString(),
                $item->changedAt()?->format(self::TIMESTAMP),
                $organizationId,
                $item->id()->toString(),
                $item->savedState()->value,
            ],
        );
        if (1 !== $updated) {
            throw new \LogicException(\sprintf('Check item %s is not the tenant\'s or changed since it was read; read it with lockedItem.', $item->id()->toString()));
        }
    }

    private function requireTransactionAndTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Checklists are read and saved in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A checklist %s from the database is not a string.', $column));
    }
}
