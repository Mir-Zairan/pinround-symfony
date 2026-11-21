<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * Back, "2 of 3" and Next above the round page's one shown screenshot. Back and Next are links to the round page with
 * the neighbour's query, so they work without JavaScript; there is none past either end.
 */
final readonly class ScreenshotPager
{
    /**
     * @param int                     $shownNumber    from 1
     * @param array<string, int>|null $backParameters the query of the screenshot before; null on the first
     * @param array<string, int>|null $nextParameters the query of the screenshot after; null on the last
     */
    private function __construct(
        public int $shownNumber,
        public int $count,
        public ?array $backParameters,
        public ?array $nextParameters,
    ) {
    }

    /**
     * @param non-empty-list<ScreenshotThumb> $thumbs the strip, in order, exactly one of them shown
     *
     * @throws \InvalidArgumentException when no thumb or more than one is shown
     */
    public static function over(array $thumbs): self
    {
        $shownIndexes = array_keys(array_filter($thumbs, static fn (ScreenshotThumb $thumb): bool => $thumb->isShown));
        if (1 !== \count($shownIndexes)) {
            throw new \InvalidArgumentException('A screenshot strip shows exactly one screenshot.');
        }
        $shownIndex = $shownIndexes[0];

        return new self(
            $shownIndex + 1,
            \count($thumbs),
            ($thumbs[$shownIndex - 1] ?? null)?->parameters,
            ($thumbs[$shownIndex + 1] ?? null)?->parameters,
        );
    }

    /** "2 of 3". */
    public function positionLabel(): string
    {
        return \sprintf('%d of %d', $this->shownNumber, $this->count);
    }
}
