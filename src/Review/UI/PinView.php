<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * One pin as the screenshot components draw it. The position is a percentage of the picture's width and height, so a
 * pin keeps its spot at any display size (media-pipeline.md, Pin coordinate model).
 */
final readonly class PinView
{
    public const int NOTE_MAX_LENGTH = 1000;

    /** A posted pin x or y as pin_markup.js writes it ("12.50"): is_numeric would also let in a sign, an exponent and spaces. */
    public const string POSTED_PERCENT_PATTERN = '/\A\d{1,3}(?:\.\d+)?\z/';

    /** Past this x the note's bubble opens to the left of the ring, so it stays inside the picture. */
    private const float BUBBLE_FLIP_PERCENT = 60.0;

    public function __construct(
        public int $number,
        public float $xPercent,
        public float $yPercent,
        public string $note,
        public bool $isAiSuggested = false,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('A pin number starts at 1.');
        }
        if (!self::isPercent($xPercent) || !self::isPercent($yPercent)) {
            throw new \InvalidArgumentException('A pin must sit inside the picture: x and y go from 0 to 100.');
        }
        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A pin note holds at most %d characters.', self::NOTE_MAX_LENGTH));
        }
    }

    /**
     * The note on one line: line breaks and other control characters (CR, LF, U+2028) become a space, for the
     * accessible name and the bubble, which have no line structure.
     */
    public function singleLineNote(): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $this->note) ?? '');
    }

    /** "Pin 2: header is cut off", or "Pin 2" while the note is empty. */
    public function accessibleName(): string
    {
        $note = $this->singleLineNote();

        return '' === $note ? \sprintf('Pin %d', $this->number) : \sprintf('Pin %d: %s', $this->number, $note);
    }

    public function isBubbleOnLeft(): bool
    {
        return $this->xPercent > self::BUBBLE_FLIP_PERCENT;
    }

    private static function isPercent(float $value): bool
    {
        return $value >= 0.0 && $value <= 100.0;
    }
}
