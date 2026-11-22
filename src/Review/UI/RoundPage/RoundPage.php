<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * What the round page shows of one round: the ticket line, the header, the tab row of its screenshots, the one shown
 * screenshot and its side panel.
 */
final readonly class RoundPage
{
    /**
     * @param string               $title            for example "Round 2"
     * @param string               $detail           the header's muted rest after the title, for example "shot today, 09:12 · Ready for Mia"
     * @param string               $tabsLabel        the tab row's accessible name: "Screen areas" or "Screenshots"
     * @param list<ScreenshotTab>  $tabs             in order; none for a round with nothing to show. The page shows the
     *                                               row only with two or more
     * @param ScreenshotPager|null $pager            null exactly when $tabs is empty
     * @param ShownScreenshot|null $shown            null exactly when $tabs is empty
     * @param RoundPanel|null      $panel            the shown screen area's; null for a round without areas
     * @param ScreenshotPanel|null $screenshotPanel  the shown screenshot's, for a round without areas; null otherwise
     * @param string|null          $verdictGiverName the first name of whoever else the open round is ready for, who
     *                                               gives its verdict; null when it is the viewer or nobody is named
     * @param string               $emptyMessage     what the page says when $tabs is empty
     */
    public function __construct(
        public string $ticketKey,
        public string $ticketTitle,
        public string $title,
        public string $detail,
        public string $tabsLabel,
        public array $tabs,
        public ?ScreenshotPager $pager,
        public ?ShownScreenshot $shown,
        public ?RoundPanel $panel,
        public ?ScreenshotPanel $screenshotPanel,
        public ?string $verdictGiverName,
        public string $emptyMessage,
    ) {
    }

    /**
     * The query that names the shown screenshot, "?area=N" or "?screenshot=N", which the round page and the full-screen
     * review both read; empty when the round has nothing to show.
     *
     * @return array<string, int>
     */
    public function shownParameters(): array
    {
        return $this->shownTab()->parameters ?? [];
    }

    /**
     * The round page's screenshot-pager target the tab is, which the left and right arrow keys follow: "back" for the
     * tab before the shown one, "next" for the one after it, null for any other.
     */
    public function pagerTargetOf(ScreenshotTab $tab): ?string
    {
        return match ($tab->parameters) {
            $this->pager?->backParameters => 'back',
            $this->pager?->nextParameters => 'next',
            default => null,
        };
    }

    /** The tab of the shown screenshot; null when the round has nothing to show. */
    public function shownTab(): ?ScreenshotTab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->isShown) {
                return $tab;
            }
        }

        return null;
    }
}
