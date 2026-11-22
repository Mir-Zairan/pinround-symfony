<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\Controller\RoundController;

/**
 * The screenshots before and after the shown one: the full-screen review's Back and Next, and the round page's left and
 * right arrow keys, which follow the neighbouring tabs. Each is the neighbour's query, so the links work without
 * JavaScript; there is none past either end.
 */
final readonly class ScreenshotPager
{
    /**
     * The full-screen review's pager nav's accessible name, which must not contain "Screenshots" or "Screen areas", the
     * round page's tab rows (Playwright matches names by substring).
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
     * @param non-empty-list<ScreenshotTab> $tabs the tab row, in order, exactly one of them shown
     *
     * @throws \InvalidArgumentException when no tab or more than one is shown
     */
    public static function over(array $tabs): self
    {
        $shownIndexes = array_keys(array_filter($tabs, static fn (ScreenshotTab $tab): bool => $tab->isShown));
        if (1 !== \count($shownIndexes)) {
            throw new \InvalidArgumentException('A tab row shows exactly one screenshot.');
        }
        $shownIndex = $shownIndexes[0];

        return new self(
            $shownIndex + 1,
            \count($tabs),
            ($tabs[$shownIndex - 1] ?? null)?->parameters,
            ($tabs[$shownIndex + 1] ?? null)?->parameters,
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
