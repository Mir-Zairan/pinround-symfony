<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One row of the checklist card: a pin of Round 0 with its note and where its check stands.
 */
final readonly class CheckItemView
{
    /**
     * @param string $itemId    the item's id, lowercase
     * @param int    $pinNumber the number of its pin on its Round 0 picture, from 1
     * @param string $note      the pin's note, trimmed; empty when the pin has none
     * @param string $state     ChecklistCommands::STATE_OPEN, STATE_FIXED, STATE_OK or STATE_NOT_FIXED
     */
    public function __construct(
        public string $itemId,
        public int $pinNumber,
        public string $note,
        public string $state,
    ) {
    }
}
