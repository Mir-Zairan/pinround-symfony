<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * A pin of the earlier round in the side panel, with the verdict a reviewer gave it so far.
 */
final readonly class EarlierPinRow
{
    /**
     * @param string      $id           the pin's id, which its Fixed, Still wrong and Undo forms post
     * @param string|null $authorLine   for example "Mia · tester"; null when the author's name is not known
     * @param string      $verdictLabel for example "Fixed" or "Not checked yet"
     * @param string      $verdictClass the tag's modifier class, so the verdict is also coloured
     * @param bool        $isChecked    true once the pin has a verdict, which Undo clears
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $note,
        public ?string $authorLine,
        public string $verdictLabel,
        public string $verdictClass,
        public bool $isChecked,
    ) {
    }
}
