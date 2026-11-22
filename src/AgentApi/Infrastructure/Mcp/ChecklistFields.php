<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistView;

/**
 * A ticket's checklist as get_round_notes and get_ticket both answer it, each pin note wrapped as UntrustedText
 * (ADR-0020). It has no round number: the checklist from the report is no numbered round (ADR-0048, note of 2026-11-22).
 */
final class ChecklistFields
{
    /**
     * @return array{round_id: string, checked_ok_count: int, total: int, items: list<array{item_id: string, pin_number: int, note: string, state: string}>}
     */
    public static function of(ChecklistView $checklist): array
    {
        return [
            'round_id' => $checklist->roundId,
            'checked_ok_count' => $checklist->checkedOkCount,
            'total' => $checklist->total,
            'items' => array_map(static fn (CheckItemView $item): array => [
                'item_id' => $item->itemId,
                'pin_number' => $item->pinNumber,
                'note' => UntrustedText::wrap($item->note),
                'state' => $item->state,
            ], $checklist->items),
        ];
    }
}
