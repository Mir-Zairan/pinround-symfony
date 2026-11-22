<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\RoundPage;

use App\Review\UI\RoundPage\ScreenshotPager;
use App\Review\UI\RoundPage\ScreenshotTab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotPagerTest extends TestCase
{
    #[Test]
    public function theFirstShownHasNoBackAndTheNextScreenshotsQuery(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 1));

        self::assertNull($pager->backParameters);
        self::assertSame(['screenshot' => 2], $pager->nextParameters);
    }

    #[Test]
    public function aMiddleShownHasBothNeighboursQueries(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 2));

        self::assertSame(['screenshot' => 1], $pager->backParameters);
        self::assertSame(['screenshot' => 3], $pager->nextParameters);
    }

    #[Test]
    public function theLastShownHasNoNext(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 3));

        self::assertSame(['screenshot' => 2], $pager->backParameters);
        self::assertNull($pager->nextParameters);
    }

    #[Test]
    public function aStripOfOneHasNeitherBackNorNext(): void
    {
        $pager = ScreenshotPager::over(self::strip(1, 1));

        self::assertNull($pager->backParameters);
        self::assertNull($pager->nextParameters);
    }

    #[Test]
    public function screenAreasPageByTheirAreaQuery(): void
    {
        $tabs = [
            new ScreenshotTab(1, 'Cart', ['area' => 0], 'Passed', 'area-chip--passed', 'screenshot-thumb-state-0', false),
            new ScreenshotTab(2, 'Checkout', ['area' => 3], 'Not reviewed', 'area-chip--none', 'screenshot-thumb-state-3', true),
        ];

        $pager = ScreenshotPager::over($tabs);

        self::assertSame(['area' => 0], $pager->backParameters, 'The neighbour\'s own query, not its place in the strip.');
        self::assertNull($pager->nextParameters);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function shownCountsOtherThanOne(): iterable
    {
        yield 'none shown' => [[]];
        yield 'two shown' => [[1, 3]];
    }

    /**
     * @param list<int> $shownNumbers
     */
    #[Test]
    #[DataProvider('shownCountsOtherThanOne')]
    public function aStripMustShowExactlyOneScreenshot(array $shownNumbers): void
    {
        $tabs = [
            self::tab(1, \in_array(1, $shownNumbers, true)),
            self::tab(2, \in_array(2, $shownNumbers, true)),
            self::tab(3, \in_array(3, $shownNumbers, true)),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A tab row shows exactly one screenshot.');

        ScreenshotPager::over($tabs);
    }

    #[Test]
    public function overScreenshotsPagesByTheScreenshotQueryAndStopsAtBothEnds(): void
    {
        $first = ScreenshotPager::overScreenshots(1, 3);
        $middle = ScreenshotPager::overScreenshots(2, 3);
        $last = ScreenshotPager::overScreenshots(3, 3);
        $alone = ScreenshotPager::overScreenshots(1, 1);

        self::assertSame([null, ['screenshot' => 2]], [$first->backParameters, $first->nextParameters]);
        self::assertSame([['screenshot' => 1], ['screenshot' => 3]], [$middle->backParameters, $middle->nextParameters]);
        self::assertSame([['screenshot' => 2], null], [$last->backParameters, $last->nextParameters]);
        self::assertSame([null, null], [$alone->backParameters, $alone->nextParameters]);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function screenshotsNotInTheRound(): iterable
    {
        yield 'zero' => [0, 3];
        yield 'one past the last' => [4, 3];
        yield 'a round with none' => [1, 0];
    }

    #[Test]
    #[DataProvider('screenshotsNotInTheRound')]
    public function overScreenshotsRefusesAShownNumberTheRoundHasNot(int $shownNumber, int $count): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The shown screenshot is one of the round\'s.');

        ScreenshotPager::overScreenshots($shownNumber, $count);
    }

    /**
     * @return non-empty-list<ScreenshotTab>
     */
    private static function strip(int $count, int $shownNumber): array
    {
        $tabs = [self::tab(1, 1 === $shownNumber)];
        for ($number = 2; $number <= $count; ++$number) {
            $tabs[] = self::tab($number, $number === $shownNumber);
        }

        return $tabs;
    }

    private static function tab(int $number, bool $isShown): ScreenshotTab
    {
        return new ScreenshotTab($number, 'Screenshot '.$number, ['screenshot' => $number], '0 pins', 'screenshot-thumb__pins--none', null, $isShown);
    }
}
