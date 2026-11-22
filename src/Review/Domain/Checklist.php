<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A ticket's checklist with its items (P05.T6): a round of kind checklist, shared from the start with no number, owned
 * by whoever reported Round 0, with one open item per pin of Round 0. It is "Checklist from the report", not a numbered
 * round (ADR-0048, note of 2026-11-22), so the first round shared after the report is Round 1. A ticket gets one;
 * Checklists::add refuses a second.
 */
final readonly class Checklist
{
    /**
     * @param non-empty-list<CheckItem> $items in the order of Round 0's pins
     */
    private function __construct(
        private RoundId $id,
        private OrganizationId $organizationId,
        private TicketId $ticketId,
        private UserId $ownerId,
        private \DateTimeImmutable $createdAt,
        private array $items,
    ) {
    }

    /**
     * The checklist of Round 0's pins, shared from the start.
     *
     * @param \Closure(): CheckItemId $nextItemId called once per pin
     *
     * @return self|null null when Round 0 has no pins, as there is nothing to check
     *
     * @throws \InvalidArgumentException when a pin comes twice
     */
    public static function fromReport(RoundId $id, OrganizationId $organizationId, TicketId $ticketId, ReportPins $report, \Closure $nextItemId, \DateTimeImmutable $createdAt): ?self
    {
        $items = [];
        $pinIds = [];
        foreach ($report->pinIds() as $pinId) {
            if (isset($pinIds[$pinId->toString()])) {
                throw new \InvalidArgumentException('A checklist has one item per pin.');
            }
            $pinIds[$pinId->toString()] = true;
            $items[] = CheckItem::open($nextItemId(), $id, $ticketId, $pinId);
        }
        if ([] === $items) {
            return null;
        }

        return new self($id, $organizationId, $ticketId, $report->reporterId(), $createdAt, $items);
    }

    public function id(): RoundId
    {
        return $this->id;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function ticketId(): TicketId
    {
        return $this->ticketId;
    }

    public function ownerId(): UserId
    {
        return $this->ownerId;
    }

    /**
     * When the checklist was made, which is also when it was shared and when each item was made.
     */
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return non-empty-list<CheckItem> in the order of Round 0's pins
     */
    public function items(): array
    {
        return $this->items;
    }
}
