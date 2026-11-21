<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

use App\Review\UI\ScreenshotView;

/**
 * What the full-screen review shows of one round (full-screen-review.md): one picture, the pins to walk on it, and a
 * bar per pin, of which one is shown at a time. For a screen area the pins are the earlier round's, to check; for a
 * screenshot of a round without areas, its own.
 */
final readonly class FocusPage
{
    public const string SIDE_AFTER = 'after';
    public const string SIDE_BEFORE = 'before';

    /**
     * @param string               $line            for example "SHOP-1 · Round 2 · Per product · section 2 of 3"
     * @param int|null             $areaPosition    from 0, the area shown; null exactly when the round has no screen areas
     * @param string               $side            SIDE_AFTER or SIDE_BEFORE, the picture shown
     * @param bool                 $hasBefore       whether the area, or the shared draft's screenshot, has an earlier
     *                                              picture to switch to
     * @param ScreenshotView|null  $picture         null exactly when the round has nothing to show
     * @param list<FocusPinStop>   $stops           the pins to walk, by number
     * @param FocusPinStop|null    $current         the stop shown first; null exactly when $stops is empty
     * @param bool                 $mayGiveVerdicts true shows the forms, as RoundPages::mayGiveVerdictsOn decides; never
     *                                              for a round without areas
     * @param array<string, int>   $shownParameters the query that names the picture shown, as RoundPage::shownParameters
     *                                              gives it, for the walk's links and the way back to the round page
     * @param ScreenshotPager|null $pager           Back and Next between the screenshots of a round without areas; null
     *                                              for a round with areas and for one with nothing to show
     * @param string               $emptyMessage    what the page says when $picture is null
     */
    public function __construct(
        public string $ticketKey,
        public string $line,
        public ?int $areaPosition,
        public string $side,
        public bool $hasBefore,
        public ?ScreenshotView $picture,
        public array $stops,
        public ?FocusPinStop $current,
        public bool $mayGiveVerdicts,
        public array $shownParameters,
        public ?ScreenshotPager $pager,
        public string $emptyMessage,
    ) {
    }

    /**
     * The stop a choice on $position moves to: the next one, and the first after the last.
     *
     * @param int $position from 1
     * @param int $count    at least 1
     */
    public static function nextPositionAfter(int $position, int $count): int
    {
        return $position % $count + 1;
    }

    /**
     * @param int $position from 1
     * @param int $count    at least 1
     */
    public static function previousPositionBefore(int $position, int $count): int
    {
        return ($position + $count - 2) % $count + 1;
    }
}
