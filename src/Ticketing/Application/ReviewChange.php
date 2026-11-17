<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

/**
 * A checklist or verdict change of a ticket's rounds, as its history records it. The labels are null when Review could
 * not name the pin or area.
 */
final readonly class ReviewChange
{
    public const string CHECK_ITEM_CHANGED = 'check_item_changed';
    public const string PIN_VERDICT_SET = 'pin_verdict_set';
    public const string PIN_VERDICT_UNDONE = 'pin_verdict_undone';
    public const string AREA_VERDICT_SAVED = 'area_verdict_saved';

    public const array KINDS = [self::CHECK_ITEM_CHANGED, self::PIN_VERDICT_SET, self::PIN_VERDICT_UNDONE, self::AREA_VERDICT_SAVED];

    /**
     * @param string      $kind      one of KINDS, which is also the tkt_ticket_event type
     * @param string      $outcome   the item's new state, the pin's verdict (the undone one for an undo) or the area's
     *                               verdict, as the Review event carries it
     * @param int|null    $pinNumber from 1; null for an area verdict
     * @param string|null $areaName  null for a checklist item
     *
     * @throws \InvalidArgumentException when the kind is not one of KINDS or the outcome is empty
     */
    public function __construct(
        public string $kind,
        public string $outcome,
        public ?int $pinNumber,
        public ?string $areaName,
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a kind of review change.', $kind));
        }
        if ('' === $outcome) {
            throw new \InvalidArgumentException('A review change has an outcome.');
        }
    }
}
