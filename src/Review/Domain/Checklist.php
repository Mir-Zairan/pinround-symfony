<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A ticket's checklist round with its items (P05.T6): a shared round of kind checklist, owned by whoever reported
 * Round 0, with one open item per pin of Round 0. A ticket gets one; Checklists::add refuses a second.
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
        private int $number,
        private \DateTimeImmutable $createdAt,
        private array $items,
    ) {
    }

    /**
     * The checklist of Round 0's pins, shared from the start as round $number.
     *
     * @param int                     $number     the next free round number of the ticket, which the caller reads; from
     *                                            1, as 0 is the report
     * @param \Closure(): CheckItemId $nextItemId called once per pin
     *
     * @return self|null null when Round 0 has no pins, as there is nothing to check
     *
     * @throws \InvalidArgumentException when the number is below 1 or a pin comes twice
     */
    public static function fromReport(RoundId $id, OrganizationId $organizationId, TicketId $ticketId, ReportPins $report, int $number, \Closure $nextItemId, \DateTimeImmutable $createdAt): ?self
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('A checklist round gets a number from 1; Round 0 is the report.');
        }
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

        return new self($id, $organizationId, $ticketId, $report->reporterId(), $number, $createdAt, $items);
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

    public function number(): int
    {
        return $this->number;
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
