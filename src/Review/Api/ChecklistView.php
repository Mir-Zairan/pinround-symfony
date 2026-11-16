<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A ticket's checklist round for the checklist card, with the counts of its "k of n checked OK" summary.
 */
final readonly class ChecklistView
{
    /** How many items are confirmed OK. */
    public int $checkedOkCount;

    /** How many items the checklist has; at least 1. */
    public int $total;

    /**
     * @param string                        $roundId the checklist round's id, lowercase
     * @param int                           $number  its round number on the ticket, from 1
     * @param non-empty-list<CheckItemView> $items   by Round 0 screenshot position, then pin number
     */
    public function __construct(
        public string $roundId,
        public int $number,
        public array $items,
    ) {
        $this->checkedOkCount = \count(array_filter($items, static fn (CheckItemView $item): bool => ChecklistCommands::STATE_OK === $item->state));
        $this->total = \count($items);
    }
}
