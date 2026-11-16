<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\Checklist;

use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\RoundKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Unit\Review\Application\InMemoryChecklists;
use App\Tests\Unit\Review\Application\InMemoryRounds;

/**
 * Like DbalChecklistQueries, over what InMemoryChecklists and InMemoryRounds store: the tenant's items and pins only, a
 * malformed id is null, and items come by their pin's screenshot position, then pin number.
 */
final readonly class InMemoryChecklistQueries implements ChecklistQueries
{
    public function __construct(
        private InMemoryChecklists $checklists,
        private InMemoryRounds $rounds,
        private TenantContext $tenantContext,
    ) {
    }

    public function accessFactsOf(string $itemId): ?CheckItemAccessFacts
    {
        $organizationId = $this->tenantContext->require();
        try {
            $id = new CheckItemId($itemId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $stored = $this->checklists->storedItems[$id->toString()] ?? null;
        if (null === $stored || !$stored[0]->equals($organizationId)) {
            return null;
        }
        $item = $stored[1];

        return new CheckItemAccessFacts($organizationId, $id->toString(), $item->roundId()->toString(), $item->ticketId(), $this->placedPinOf($organizationId, $item->pinId())[1]->number());
    }

    public function forTicket(TicketId $ticketId): ?ChecklistView
    {
        $organizationId = $this->tenantContext->require();
        foreach ($this->rounds->stored as $round) {
            if (RoundKind::Checklist !== $round->kind() || !$round->organizationId()->equals($organizationId) || !$round->ticketId()->equals($ticketId)) {
                continue;
            }
            $sortableItems = [];
            foreach ($this->checklists->storedItems as [$itemOrganizationId, $item]) {
                if ($itemOrganizationId->equals($organizationId) && $item->roundId()->equals($round->id())) {
                    [$position, $pin] = $this->placedPinOf($organizationId, $item->pinId());
                    $sortableItems[] = [$position, $pin->number(), new CheckItemView($item->id()->toString(), $pin->number(), $pin->note(), $item->state()->value)];
                }
            }
            if ([] === $sortableItems) {
                return null;
            }
            usort($sortableItems, static fn (array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);

            return new ChecklistView($round->id()->toString(), $round->number() ?? throw new \LogicException('A checklist round always has a number.'), array_column($sortableItems, 2));
        }

        return null;
    }

    /**
     * @return array{int, Pin} the position of the pin's screenshot, and the pin
     */
    private function placedPinOf(OrganizationId $organizationId, PinId $pinId): array
    {
        foreach ($this->rounds->stored as $round) {
            if (!$round->organizationId()->equals($organizationId)) {
                continue;
            }
            foreach ($round->screenshots() as $screenshot) {
                foreach ($screenshot->pins() as $pin) {
                    if ($pin->id()->equals($pinId)) {
                        return [$screenshot->position(), $pin];
                    }
                }
            }
        }

        throw new \LogicException(\sprintf('Pin %s of a stored check item is not stored.', $pinId->toString()));
    }
}
