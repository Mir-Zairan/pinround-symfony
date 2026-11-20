<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A picture in a round, by its Media id, with an optional title and summary shown above it as plain text (FR-RND-16)
 * and its pins. Pin numbers are per screenshot.
 */
final readonly class Screenshot
{
    /** The same caps as the screenshot components (ScreenshotView) and rvw_screenshot_title_check / _summary_check. */
    public const int TITLE_MAX_LENGTH = 120;
    public const int SUMMARY_MAX_LENGTH = 600;

    // No requirement gives a limit; chosen so one screenshot's pins stay readable and one insert stays small.
    public const int MAX_PINS = 100;

    /** ADR-0048: a screenshot keeps at most 5 tries, the first one included. ScreenshotTriesFull::REASON names it. */
    public const int MAX_TRIES = 5;

    // Version nibble 7 and variant bits 10xx, as Media's ids are; \z, because $ would also accept a trailing newline.
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @param list<Pin> $pins
     */
    private function __construct(
        private ScreenshotId $id,
        private string $mediaId,
        private int $position,
        private ?string $title,
        private ?string $summary,
        private array $pins,
        private ?VariantLabel $variantLabel,
        private ?ScreenshotVerdict $verdict,
        private ?ScreenshotId $retakeOf,
    ) {
    }

    /**
     * A screenshot with no compare label and no verdict. The title and summary are trimmed, and a blank one is none.
     * Lengths count characters (code points), as PostgreSQL's char_length does.
     *
     * @param string    $mediaId  the uuid of the Media object that holds the picture, in any case
     * @param int       $position its place in the round, from 0
     * @param list<Pin> $pins     in any order; kept in the order of their numbers
     *
     * @throws \InvalidArgumentException when the media id is not a UUIDv7, the position is below 0, the title is longer
     *                                   than TITLE_MAX_LENGTH or the summary than SUMMARY_MAX_LENGTH characters, a pin
     *                                   number appears twice, or there are more than MAX_PINS pins
     */
    public static function of(ScreenshotId $id, string $mediaId, int $position, ?string $title, ?string $summary, array $pins): self
    {
        $lowercaseMediaId = strtolower($mediaId);
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $lowercaseMediaId)) {
            throw new \InvalidArgumentException('A screenshot needs the id of an uploaded picture.');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('A screenshot position starts at 0.');
        }
        $trimmedTitle = self::blankToNull($title);
        if (null !== $trimmedTitle && mb_strlen($trimmedTitle) > self::TITLE_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A picture title holds at most %d characters.', self::TITLE_MAX_LENGTH));
        }
        $trimmedSummary = self::blankToNull($summary);
        if (null !== $trimmedSummary && mb_strlen($trimmedSummary) > self::SUMMARY_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A picture summary holds at most %d characters.', self::SUMMARY_MAX_LENGTH));
        }
        if (\count($pins) > self::MAX_PINS) {
            throw new \InvalidArgumentException(\sprintf('A picture holds at most %d pins.', self::MAX_PINS));
        }
        $numbers = [];
        foreach ($pins as $pin) {
            if (isset($numbers[$pin->number()])) {
                throw new \InvalidArgumentException(\sprintf('Pin %d appears twice on one screenshot.', $pin->number()));
            }
            $numbers[$pin->number()] = true;
        }
        usort($pins, static fn (Pin $first, Pin $second): int => $first->number() <=> $second->number());

        return new self($id, $lowercaseMediaId, $position, $trimmedTitle, $trimmedSummary, $pins, null, null, null);
    }

    /**
     * A stored screenshot as Rounds reads it back, under the rules of of() and asTryOf().
     *
     * @param list<Pin>         $pins
     * @param ScreenshotId|null $retakeOf the first try of its chain; null when it is a first try itself
     *
     * @throws \InvalidArgumentException when of() or asTryOf() would refuse it
     */
    public static function restored(ScreenshotId $id, string $mediaId, int $position, ?string $title, ?string $summary, array $pins, ?VariantLabel $variantLabel, ?ScreenshotVerdict $verdict, ?ScreenshotId $retakeOf): self
    {
        $checked = self::of($id, $mediaId, $position, $title, $summary, $pins);
        $restored = new self($id, $checked->mediaId, $position, $checked->title, $checked->summary, $checked->pins, $variantLabel, $verdict, null);

        return null === $retakeOf ? $restored : $restored->asTryOf($retakeOf);
    }

    /**
     * This screenshot as a later try in the chain that $firstTryId starts (ADR-0048).
     *
     * @throws \InvalidArgumentException when $firstTryId is this screenshot's own id
     */
    public function asTryOf(ScreenshotId $firstTryId): self
    {
        if ($firstTryId->equals($this->id)) {
            throw new \InvalidArgumentException('A screenshot cannot be a retake of itself.');
        }

        return new self($this->id, $this->mediaId, $this->position, $this->title, $this->summary, $this->pins, $this->variantLabel, $this->verdict, $firstTryId);
    }

    /**
     * This screenshot as the first try of its chain, as when the try before it was removed.
     */
    public function asFirstTry(): self
    {
        return new self($this->id, $this->mediaId, $this->position, $this->title, $this->summary, $this->pins, $this->variantLabel, $this->verdict, null);
    }

    /**
     * This screenshot with another title and summary, trimmed as of() trims them.
     *
     * @throws \InvalidArgumentException when the title is longer than TITLE_MAX_LENGTH or the summary than
     *                                   SUMMARY_MAX_LENGTH characters
     */
    public function withText(?string $title, ?string $summary): self
    {
        $checked = self::of($this->id, $this->mediaId, $this->position, $title, $summary, $this->pins);

        return new self($this->id, $this->mediaId, $this->position, $checked->title, $checked->summary, $this->pins, $this->variantLabel, $this->verdict, $this->retakeOf);
    }

    /**
     * This screenshot with $pins in place of its pins.
     *
     * @param list<Pin> $pins in any order; kept in the order of their numbers
     *
     * @throws \InvalidArgumentException when a pin number appears twice or there are more than MAX_PINS pins
     */
    public function withPins(array $pins): self
    {
        $checked = self::of($this->id, $this->mediaId, $this->position, $this->title, $this->summary, $pins);

        return new self($this->id, $this->mediaId, $this->position, $this->title, $this->summary, $checked->pins, $this->variantLabel, $this->verdict, $this->retakeOf);
    }

    public function withVerdict(?ScreenshotVerdict $verdict): self
    {
        return new self($this->id, $this->mediaId, $this->position, $this->title, $this->summary, $this->pins, $this->variantLabel, $verdict, $this->retakeOf);
    }

    public function id(): ScreenshotId
    {
        return $this->id;
    }

    public function mediaId(): string
    {
        return $this->mediaId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    /**
     * @return list<Pin> by number
     */
    public function pins(): array
    {
        return $this->pins;
    }

    public function variantLabel(): ?VariantLabel
    {
        return $this->variantLabel;
    }

    public function verdict(): ?ScreenshotVerdict
    {
        return $this->verdict;
    }

    /**
     * The first try of this screenshot's chain, which it is a later try of; null when it is a first try. Every later
     * try of a chain points at the first, never at the try before it.
     */
    public function retakeOf(): ?ScreenshotId
    {
        return $this->retakeOf;
    }

    /**
     * The id of the first try of this screenshot's chain, its own id when it is the first.
     */
    public function firstTryId(): ScreenshotId
    {
        return $this->retakeOf ?? $this->id;
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }
}
