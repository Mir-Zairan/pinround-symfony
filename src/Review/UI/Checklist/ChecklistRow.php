<?php

declare(strict_types=1);

namespace App\Review\UI\Checklist;

/**
 * One row of a checklist card: a pin with its picture, its note, its state chip and the buttons the viewer may use.
 */
final readonly class ChecklistRow
{
    /**
     * @param string $itemId       the check item's id, lowercase
     * @param int    $pinNumber    the number of its pin on its picture, from 1; two pictures each have a pin 1
     * @param string $pinLabel     the pin with its picture, for example "Screenshot 2 · pin 1"
     * @param string $note         the pin's note as shown; "(no note)" for a pin with none
     * @param string $stateLabel   for example "Fixed · to check"
     * @param string $stateClass   the chip's colour class, for example "check-state--fixed"
     * @param bool   $mayMarkFixed whether the row shows "Mark fixed"
     * @param bool   $mayCheck     whether the row shows "OK" and "Not fixed"
     */
    public function __construct(
        public string $itemId,
        public int $pinNumber,
        public string $pinLabel,
        public string $note,
        public string $stateLabel,
        public string $stateClass,
        public bool $mayMarkFixed,
        public bool $mayCheck,
    ) {
    }

    public function markFixedId(): string
    {
        return ChecklistCard::MARK_FIXED_ID_PREFIX.$this->itemId;
    }

    public function okId(): string
    {
        return ChecklistCard::OK_ID_PREFIX.$this->itemId;
    }

    /**
     * @return string|null the id of the row's first button; null for a row with none
     */
    public function firstControlId(): ?string
    {
        if ($this->mayMarkFixed) {
            return $this->markFixedId();
        }

        return $this->mayCheck ? $this->okId() : null;
    }
}
