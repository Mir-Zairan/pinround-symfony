<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\Controller\RoundController;

/**
 * Back, "2 of 3" and Next above the round page's one shown screenshot. Back and Next are links to the round page with
 * the neighbour's query, so they work without JavaScript; there is none past either end.
 */
final readonly class ScreenshotPager
{
    /**
     * The pager nav's accessible name: it shares the round page with the strip, named "Screenshots" or "Screen areas",
     * so it must not contain either word (WCAG 2.2 names each nav uniquely; Playwright matches names by substring).
     */
    public const string NAV_LABEL = 'Back and next';

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

    /**
     * Over the screenshots of a round without areas, each named by "?screenshot=N", for a page with no strip to build
     * the pager from.
     *
     * @param int $shownNumber from 1
     * @param int $count       at least $shownNumber
     *
     * @throws \InvalidArgumentException when $shownNumber is not one of the $count screenshots
     */
    public static function overScreenshots(int $shownNumber, int $count): self
    {
        if ($shownNumber < 1 || $shownNumber > $count) {
            throw new \InvalidArgumentException('The shown screenshot is one of the round\'s.');
        }

        return new self(
            $shownNumber,
            $count,
            1 === $shownNumber ? null : [RoundController::SCREENSHOT_PARAMETER => $shownNumber - 1],
            $count === $shownNumber ? null : [RoundController::SCREENSHOT_PARAMETER => $shownNumber + 1],
        );
    }

    /** "2 of 3". */
    public function positionLabel(): string
    {
        return \sprintf('%d of %d', $this->shownNumber, $this->count);
    }
}
