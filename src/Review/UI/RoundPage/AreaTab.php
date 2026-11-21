<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * One screen area as the round page's strip names it: its name and its state, which a verdict's stream shows again
 * without signing the strip's pictures.
 */
final readonly class AreaTab
{
    /**
     * @param int    $position   from 0, the area's place in the round, which its thumb's link names
     * @param string $stateLabel for example "Passed" or "3 pins"
     * @param string $stateClass the tag's modifier class, so the state is also coloured
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
