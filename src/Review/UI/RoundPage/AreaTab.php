<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * One screen area's tab on the round page: its name and the state chip beside it.
 */
final readonly class AreaTab
{
    /**
     * @param int    $position   from 0, the area's place in the round, which the tab's link names
     * @param string $stateLabel for example "Passed" or "3 pins"
     * @param string $stateClass the chip's modifier class, so the state is also coloured
     */
    public function __construct(
        public int $position,
        public string $name,
        public string $stateLabel,
        public string $stateClass,
        public bool $isSelected,
    ) {
    }
}
