<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\Checklist\ChecklistRow;

/**
 * A pin of a picture the round page shows, as a row of its side panel: its note, who put it, and, when the pin is a
 * check item (the report's pins, a findings round's), where its check stands with the buttons the viewer may use.
 */
final readonly class PicturePinRow
{
    /**
     * @param int               $number     the number on its marker, from 1
     * @param string|null       $authorLine for example "Mia · tester"; null when the author's name is not known
     * @param ChecklistRow|null $check      the pin's check item; null for a pin that is none, such as a self-check's
     */
    public function __construct(
        public int $number,
        public string $note,
        public ?string $authorLine,
        public ?ChecklistRow $check,
    ) {
    }
}
