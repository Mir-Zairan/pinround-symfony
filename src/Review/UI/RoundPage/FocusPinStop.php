<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\Checklist\ChecklistRow;

/**
 * One pin of the full-screen review's walk, with the bar that shows it: on a screen area an earlier round's pin to check,
 * on a screenshot of a round without areas one of its own pins.
 */
final readonly class FocusPinStop
{
    /**
     * @param string            $id               the pin's id, which its Fixed, Still wrong and Undo forms post
     * @param int               $position         from 1, its place in the walk, the page's "?pin=" value
     * @param int               $number           the number on its ring; a removed pin leaves a gap, so it may differ from $position
     * @param string            $label            for example "Pin 2 of 5 · Mia"; without the name when it is not known
     * @param bool              $isChecked        true once the pin has a verdict, which Undo clears
     * @param int               $previousPosition the stop before it, the last one from the first
     * @param int               $nextPosition     the stop after it, the first one from the last
     * @param ChecklistRow|null $check            the pin's check item, as RoundPages::checkRowsOf finds it; null for a pin that
     *                                            is none
     */
    public function __construct(
        public string $id,
        public int $position,
        public int $number,
        public string $note,
        public string $label,
        public bool $isChecked,
        public int $previousPosition,
        public int $nextPosition,
        public ?ChecklistRow $check,
    ) {
    }
}
