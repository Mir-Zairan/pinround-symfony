<?php

declare(strict_types=1);

namespace App\Notification\Application\Digest;

/**
 * A person's email digest: their unread bell items created in the half-open window (since, coveredUntil], newest first.
 * The next digest starts at this one's coveredUntil, so an item is listed in one digest only and none falls between two.
 */
final readonly class Digest
{
    /** The most items one email lists; the rest are counted as "and N more in pinround". */
    public const int MAX_LISTED_ITEMS = 20;

    /** An item waits this long unread before email reminds of it. */
    public const int MIN_ITEM_AGE_SECONDS = 3600;

    /**
     * @param list<DigestItem> $items     newest first, at most MAX_LISTED_ITEMS
     * @param int              $itemCount every item of the window, the listed ones included
     */
    public function __construct(
        public array $items,
        public int $itemCount,
        public \DateTimeImmutable $coveredUntil,
    ) {
        if (\count($items) > self::MAX_LISTED_ITEMS || $itemCount < \count($items)) {
            throw new \InvalidArgumentException(\sprintf('A digest lists at most %d items and counts at least the listed ones.', self::MAX_LISTED_ITEMS));
        }
    }

    /**
     * The newest creation time an item of a digest made at $now may have.
     */
    public static function coveredUntilAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', self::MIN_ITEM_AGE_SECONDS));
    }

    public function isEmpty(): bool
    {
        return 0 === $this->itemCount;
    }

    /**
     * How many items the email counts but does not list.
     */
    public function unlistedCount(): int
    {
        return $this->itemCount - \count($this->items);
    }
}
