<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\Checklist;
use App\Review\Domain\Checklists;
use App\Review\Domain\FindingsChecklist;
use App\Review\Domain\PinId;
use App\Review\Domain\ReportPins;
use App\Review\Domain\Round;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;

/**
 * Like DbalChecklists: a checklist round is a round of $rounds, so it takes a number Rounds::nextFreeNumberOf then
 * counts and a second round with that number is refused. A round id met again is a LogicException; a second checklist
 * of a ticket, or a number the ticket has, is RoundSlotTaken; an item whose id was added before or whose pin is not
 * stored or is checked already is a LogicException; each refusal stores nothing. It keeps a copy of each item as added or saved, and saveItem writes only
 * over the state the item was read at. Reads and saves see the tenant's checklists only and need its TenantContext.
 */
final class InMemoryChecklists implements Checklists
{
    public const string ITEM_REFUSED = 'A checklist item has an id added before, or names a pin that is not stored or is in a checklist already.';

    /** @var array<string, array{OrganizationId, CheckItem}> by item id, as added or last saved */
    public array $storedItems = [];

    /** @var list<string> the item ids lockedItem was asked for, in order: each stands for the row lock DbalChecklists takes */
    public array $lockedItemIds = [];

    private int $issuedItemIds = 0;

    public function __construct(private readonly InMemoryRounds $rounds, private readonly TenantContext $tenantContext)
    {
    }

    public function nextItemIdentity(): CheckItemId
    {
        return new CheckItemId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x8300 + ++$this->issuedItemIds));
    }

    public function add(Checklist $checklist): void
    {
        $round = Round::restored(
            $checklist->id(),
            $checklist->organizationId(),
            $checklist->ticketId(),
            RoundKind::Checklist,
            $checklist->ownerId(),
            null,
            $checklist->createdAt(),
            RoundState::Open,
            1,
            [],
            $checklist->createdAt(),
            null,
            null,
            null,
        );
        foreach ($this->rounds->stored as $existing) {
            if ($existing->id()->equals($round->id())) {
                throw new \LogicException(\sprintf('Round %s is added already; a round is added once.', $round->id()->toString()));
            }
        }
        // The slots before the items, as the round row goes in before them.
        foreach ($this->rounds->stored as $existing) {
            if (!$existing->organizationId()->equals($round->organizationId()) || !$existing->ticketId()->equals($round->ticketId())) {
                continue;
            }
            // rvw_round_ticket_id_checklist_key; the checklist has no number, so rvw_round_ticket_id_number_key never meets it.
            if (RoundKind::Checklist === $existing->kind()) {
                throw new RoundSlotTaken('The ticket has this round already.');
            }
        }
        $this->refuseItemsThatCannotBeStored($checklist->organizationId(), $checklist->items());

        $this->rounds->add($round);
        $this->storeItems($checklist->organizationId(), $checklist->items());
    }

    public function addFindings(FindingsChecklist $checklist): void
    {
        $this->refuseItemsThatCannotBeStored($checklist->organizationId(), $checklist->items());

        $this->storeItems($checklist->organizationId(), $checklist->items());
    }

    public function reportPinsOf(TicketId $ticketId): ?ReportPins
    {
        $organizationId = $this->tenantContext->require();
        foreach ($this->rounds->stored as $existing) {
            if (Round::REPORT_NUMBER === $existing->number() && $existing->organizationId()->equals($organizationId) && $existing->ticketId()->equals($ticketId)) {
                $pinIds = [];
                foreach ($existing->screenshots() as $screenshot) {
                    foreach ($screenshot->pins() as $pin) {
                        $pinIds[] = $pin->id();
                    }
                }

                return new ReportPins($existing->ownerId(), $pinIds);
            }
        }

        return null;
    }

    public function lockedItem(CheckItemId $id): ?CheckItem
    {
        $organizationId = $this->tenantContext->require();
        $this->lockedItemIds[] = $id->toString();
        $stored = $this->storedItems[$id->toString()] ?? null;

        return null !== $stored && $stored[0]->equals($organizationId) ? self::copyOf($stored[1]) : null;
    }

    public function saveItem(CheckItem $item): void
    {
        $organizationId = $this->tenantContext->require();
        $stored = $this->storedItems[$item->id()->toString()] ?? null;
        if (null === $stored || !$stored[0]->equals($organizationId) || $stored[1]->state() !== $item->savedState()) {
            throw new \LogicException(\sprintf('Check item %s is not the tenant\'s or changed since it was read; read it with lockedItem.', $item->id()->toString()));
        }

        $this->storedItems[$item->id()->toString()] = [$organizationId, self::copyOf($item)];
    }

    /**
     * @param list<CheckItem> $items
     *
     * @throws \LogicException ITEM_REFUSED, as the multi-row insert refuses all of them for one
     */
    private function refuseItemsThatCannotBeStored(OrganizationId $organizationId, array $items): void
    {
        $storedPinIds = $this->storedPinIdsOf($organizationId);
        $itemPinIds = [];
        $itemIds = [];
        foreach ($items as $item) {
            $pinId = $item->pinId()->toString();
            $itemId = $item->id()->toString();
            if (!isset($storedPinIds[$pinId]) || isset($itemPinIds[$pinId]) || $this->isChecked($organizationId, $item->pinId())
                || isset($itemIds[$itemId]) || isset($this->storedItems[$itemId])) {
                throw new \LogicException(self::ITEM_REFUSED);
            }
            $itemPinIds[$pinId] = true;
            $itemIds[$itemId] = true;
        }
    }

    /**
     * @param list<CheckItem> $items
     */
    private function storeItems(OrganizationId $organizationId, array $items): void
    {
        foreach ($items as $item) {
            $this->storedItems[$item->id()->toString()] = [$organizationId, self::copyOf($item)];
        }
    }

    /**
     * @return array<string, true> the ids of every pin of the organization's stored rounds
     */
    private function storedPinIdsOf(OrganizationId $organizationId): array
    {
        $pinIds = [];
        foreach ($this->rounds->stored as $existing) {
            if (!$existing->organizationId()->equals($organizationId)) {
                continue;
            }
            foreach ($existing->screenshots() as $screenshot) {
                foreach ($screenshot->pins() as $pin) {
                    $pinIds[$pin->id()->toString()] = true;
                }
            }
        }

        return $pinIds;
    }

    private function isChecked(OrganizationId $organizationId, PinId $pinId): bool
    {
        foreach ($this->storedItems as [$itemOrganizationId, $item]) {
            if ($itemOrganizationId->equals($organizationId) && $item->pinId()->equals($pinId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The item as a fresh read gives it, at its state; it shares nothing that can change with $item.
     */
    private static function copyOf(CheckItem $item): CheckItem
    {
        return CheckItem::restored($item->id(), $item->roundId(), $item->ticketId(), $item->pinId(), $item->state(), $item->changedBy(), $item->changedAt());
    }
}
