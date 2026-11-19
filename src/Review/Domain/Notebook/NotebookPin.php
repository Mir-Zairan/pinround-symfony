<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

use App\Review\Domain\Pin;

/**
 * A pin on a notebook screenshot: its spot in percent of the picture's width and height, and its note. Its number is
 * its place in the screenshot's list, from 1.
 */
final readonly class NotebookPin
{
    private function __construct(
        private float $xPercent,
        private float $yPercent,
        private string $note,
    ) {
    }

    /**
     * The note is trimmed and may be empty; x and y are rounded to two decimals after the check, as a round's pins are.
     * Lengths count characters, as PostgreSQL's char_length does.
     *
     * @throws NotebookRefused when x or y is outside 0 to 100, the note is not valid UTF-8 or holds a NUL character, or
     *                         it is longer than Pin::NOTE_MAX_LENGTH
     */
    public static function placed(float $xPercent, float $yPercent, string $note): self
    {
        if (!self::isPercent($xPercent) || !self::isPercent($yPercent)) {
            throw NotebookRefused::because(NotebookRefused::PIN_OUTSIDE);
        }
        NotebookText::refuseUnlessSavable($note);
        $trimmedNote = trim($note);
        if (mb_strlen($trimmedNote) > Pin::NOTE_MAX_LENGTH) {
            throw NotebookRefused::because(NotebookRefused::PIN_NOTE_TOO_LONG);
        }

        return new self(round($xPercent, 2), round($yPercent, 2), $trimmedNote);
    }

    public function xPercent(): float
    {
        return $this->xPercent;
    }

    public function yPercent(): float
    {
        return $this->yPercent;
    }

    public function note(): string
    {
        return $this->note;
    }

    // NaN and the infinities fail a comparison, so they are refused too.
    private static function isPercent(float $value): bool
    {
        return $value >= 0.0 && $value <= 100.0;
    }
}
