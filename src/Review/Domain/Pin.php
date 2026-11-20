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
        private ?int $fixedOnTry,
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

        return new self($id, $number, round($xPercent, 2), round($yPercent, 2), $trimmedNote, $authorId, PinSource::Human, null, null, null);
    }

    /**
     * A stored pin as Rounds reads it back, under the rules of placedBy and withVerdict.
     *
     * @throws \InvalidArgumentException when placedBy or withVerdict would refuse it, or an AI pin has no AI state or a
     *                                   person's pin has one
     */
    public static function restored(PinId $id, int $number, float $xPercent, float $yPercent, string $note, UserId $authorId, PinSource $source, ?AiPinState $aiState, ?PinVerdict $verdict, ?int $fixedOnTry): self
    {
        if ((PinSource::Ai === $source) !== (null !== $aiState)) {
            throw new \InvalidArgumentException('Only an AI pin has an AI state, and every AI pin has one.');
        }
        $placed = self::placedBy($id, $number, $xPercent, $yPercent, $note, $authorId);

        return new self($id, $number, $placed->xPercent, $placed->yPercent, $placed->note, $authorId, $source, $aiState, null, null)
            ->withVerdict($verdict, $fixedOnTry);
    }

    /**
     * @param int|null $fixedOnTry the try of the pin's chain that fixed it, as the chain counted when it was marked
     *                             (ADR-0048); null for any other verdict, or for a verdict given outside a draft
     *
     * @throws \InvalidArgumentException when $fixedOnTry is set for a verdict other than Fixed, or is not a later try
     *                                   than the first, from 2 to Screenshot::MAX_TRIES
     */
    public function withVerdict(?PinVerdict $verdict, ?int $fixedOnTry): self
    {
        if (null !== $fixedOnTry && PinVerdict::Fixed !== $verdict) {
            throw new \InvalidArgumentException('Only a pin marked fixed names the try that fixed it.');
        }
        if (null !== $fixedOnTry && ($fixedOnTry < 2 || $fixedOnTry > Screenshot::MAX_TRIES)) {
            throw new \InvalidArgumentException(\sprintf('A pin is fixed on a later try than the first, from 2 to %d.', Screenshot::MAX_TRIES));
        }

        return new self($this->id, $this->number, $this->xPercent, $this->yPercent, $this->note, $this->authorId, $this->source, $this->aiState, $verdict, $fixedOnTry);
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

    /**
     * The try of its chain that fixed the pin, as Round::setPinVerdict stored it; null unless the verdict is Fixed.
     */
    public function fixedOnTry(): ?int
    {
        return $this->fixedOnTry;
    }

    // NaN fails both comparisons, so it is refused too.
    private static function isPercent(float $value): bool
    {
        return $value >= 0.0 && $value <= 100.0;
    }
}
