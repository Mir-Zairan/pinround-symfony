<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * A pin of the earlier round in the side panel, with the verdict a reviewer gave it so far.
 */
final readonly class EarlierPinRow
{
    /**
     * @param string $verdictLabel for example "Fixed" or "Not checked yet"
     * @param string $verdictClass the tag's modifier class, so the verdict is also coloured
     */
    public function __construct(
        public int $number,
        public string $note,
        public string $verdictLabel,
        public string $verdictClass,
    ) {
    }
}
