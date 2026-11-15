<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Media\Api\MediaImageStatus;

/**
 * A screenshot with its optional title and summary and its pins, in the order of their numbers. Numbers are per
 * screenshot and a deleted pin leaves a gap, so the list is sorted by number, never renumbered. Only a ready
 * screenshot has a picture to show; a pending or rejected one may still know its size.
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
     * @param string        $domId    a lowercase id for this screenshot's elements, unique on the page
     * @param string|null   $imageUrl what the picture is read from; only when ready, and then required
     * @param int|null      $width    the picture's pixel width; required when ready
     * @param int|null      $height   the picture's pixel height; required when ready
     * @param list<PinView> $pins
     */
    public function __construct(
        public string $domId,
        public ?string $imageUrl,
        public ?int $width,
        public ?int $height,
        public string $altText,
        ?string $title = null,
        ?string $summary = null,
        array $pins = [],
        public MediaImageStatus $status = MediaImageStatus::Ready,
    ) {
        if (1 !== preg_match('/\A[a-z][a-z0-9-]*\z/', $domId)) {
            throw new \InvalidArgumentException('A screenshot id uses lowercase letters, digits and dashes, and starts with a letter.');
        }
        if ((null === $width) !== (null === $height)) {
            throw new \InvalidArgumentException('A screenshot knows both its width and its height, or neither.');
        }
        if ((null !== $width && $width < 1) || (null !== $height && $height < 1)) {
            throw new \InvalidArgumentException('A screenshot needs a width and a height of at least 1.');
        }
        if (MediaImageStatus::Ready === $status && (null === $imageUrl || '' === $imageUrl || null === $width)) {
            throw new \InvalidArgumentException('A ready screenshot needs the URL, the width and the height of its picture.');
        }
        if (MediaImageStatus::Ready !== $status && null !== $imageUrl) {
            throw new \InvalidArgumentException('Only a ready screenshot has a picture to show.');
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

    public function isReady(): bool
    {
        return MediaImageStatus::Ready === $this->status;
    }

    public function isPending(): bool
    {
        return MediaImageStatus::Pending === $this->status;
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }
}
