<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A numbered mark on a screenshot with a note. The position is a percentage of the picture's width and height, so it
 * keeps its spot at any display size (media-pipeline.md, Pin coordinate model); rvw_pin keeps two decimals of it.
 */
final readonly class Pin
{
    /** The same cap as the pin note of the screenshot components (PinView) and rvw_pin_note_check. */
    public const int NOTE_MAX_LENGTH = 1000;

    private function __construct(
        private PinId $id,
        private int $number,
        private float $xPercent,
        private float $yPercent,
        private string $note,
        private UserId $authorId,
        private PinSource $source,
        private ?AiPinState $aiState,
        private ?PinVerdict $verdict,
    ) {
    }

    /**
     * A pin a person placed, with no verdict yet. The note is trimmed and may be empty; x and y are rounded to two
     * decimals after the check, as rvw_pin stores them.
     *
     * @throws \InvalidArgumentException when the number is below 1, x or y is outside 0 to 100, or the note is longer
     *                                   than NOTE_MAX_LENGTH characters
     */
    public static function placedBy(PinId $id, int $number, float $xPercent, float $yPercent, string $note, UserId $authorId): self
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('A pin number starts at 1.');
        }
        if (!self::isPercent($xPercent) || !self::isPercent($yPercent)) {
            throw new \InvalidArgumentException('A pin must sit inside the picture: x and y go from 0 to 100.');
        }
        $trimmedNote = trim($note);
        if (mb_strlen($trimmedNote) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A pin note holds at most %d characters.', self::NOTE_MAX_LENGTH));
        }

        return new self($id, $number, round($xPercent, 2), round($yPercent, 2), $trimmedNote, $authorId, PinSource::Human, null, null);
    }

    public function id(): PinId
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
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

    public function authorId(): UserId
    {
        return $this->authorId;
    }

    public function source(): PinSource
    {
        return $this->source;
    }

    /**
     * Null for a person's pin.
     */
    public function aiState(): ?AiPinState
    {
        return $this->aiState;
    }

    public function verdict(): ?PinVerdict
    {
        return $this->verdict;
    }

    // NaN fails both comparisons, so it is refused too.
    private static function isPercent(float $value): bool
    {
        return $value >= 0.0 && $value <= 100.0;
    }
}
