<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * A screenshot with its optional title and summary and its pins, in the order of their numbers. Numbers are per
 * screenshot and a deleted pin leaves a gap, so the list is sorted by number, never renumbered.
 */
final readonly class ScreenshotView
{
    public const int TITLE_MAX_LENGTH = 120;
    public const int SUMMARY_MAX_LENGTH = 600;

    public ?string $title;
    public ?string $summary;

    /** @var list<PinView> */
    public array $pins;

    /**
     * @param string        $domId  a lowercase id for this screenshot's elements, unique on the page
     * @param int           $width  the picture's pixel width
     * @param int           $height the picture's pixel height
     * @param list<PinView> $pins
     */
    public function __construct(
        public string $domId,
        public string $imageUrl,
        public int $width,
        public int $height,
        public string $altText,
        ?string $title = null,
        ?string $summary = null,
        array $pins = [],
    ) {
        if (1 !== preg_match('/\A[a-z][a-z0-9-]*\z/', $domId)) {
            throw new \InvalidArgumentException('A screenshot id uses lowercase letters, digits and dashes, and starts with a letter.');
        }
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('A screenshot needs a width and a height of at least 1.');
        }
        $this->title = self::blankToNull($title);
        $this->summary = self::blankToNull($summary);
        if (null !== $this->title && mb_strlen($this->title) > self::TITLE_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A picture title holds at most %d characters.', self::TITLE_MAX_LENGTH));
        }
        if (null !== $this->summary && mb_strlen($this->summary) > self::SUMMARY_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A picture summary holds at most %d characters.', self::SUMMARY_MAX_LENGTH));
        }

        $numbers = [];
        foreach ($pins as $pin) {
            if (isset($numbers[$pin->number])) {
                throw new \InvalidArgumentException(\sprintf('Pin %d appears twice on one screenshot.', $pin->number));
            }
            $numbers[$pin->number] = true;
        }
        usort($pins, static fn (PinView $first, PinView $second): int => $first->number <=> $second->number);
        $this->pins = $pins;
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }
}
