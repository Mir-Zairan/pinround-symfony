<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\RoundPage;

use App\Review\UI\RoundPage\ScreenshotPager;
use App\Review\UI\RoundPage\ScreenshotThumb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotPagerTest extends TestCase
{
    #[Test]
    public function theFirstShownHasNoBackAndTheNextScreenshotsQuery(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 1));

        self::assertSame(1, $pager->shownNumber);
        self::assertSame(3, $pager->count);
        self::assertNull($pager->backParameters);
        self::assertSame(['screenshot' => 2], $pager->nextParameters);
        self::assertSame('1 of 3', $pager->positionLabel());
    }

    #[Test]
    public function aMiddleShownHasBothNeighboursQueries(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 2));

        self::assertSame(2, $pager->shownNumber);
        self::assertSame(['screenshot' => 1], $pager->backParameters);
        self::assertSame(['screenshot' => 3], $pager->nextParameters);
        self::assertSame('2 of 3', $pager->positionLabel());
    }

    #[Test]
    public function theLastShownHasNoNext(): void
    {
        $pager = ScreenshotPager::over(self::strip(3, 3));

        self::assertSame(['screenshot' => 2], $pager->backParameters);
        self::assertNull($pager->nextParameters);
        self::assertSame('3 of 3', $pager->positionLabel());
    }

    #[Test]
    public function aStripOfOneHasNeitherBackNorNext(): void
    {
        $pager = ScreenshotPager::over(self::strip(1, 1));

        self::assertNull($pager->backParameters);
        self::assertNull($pager->nextParameters);
        self::assertSame('1 of 1', $pager->positionLabel());
    }

    #[Test]
    public function screenAreasPageByTheirAreaQuery(): void
    {
        $thumbs = [
            new ScreenshotThumb(1, 'Cart', ['area' => 0], null, 'Passed', 'area-chip--passed', 'screenshot-thumb-state-0', false),
            new ScreenshotThumb(2, 'Checkout', ['area' => 3], null, 'Not reviewed', 'area-chip--none', 'screenshot-thumb-state-3', true),
        ];

        $pager = ScreenshotPager::over($thumbs);

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
        $thumbs = [
            self::thumb(1, \in_array(1, $shownNumbers, true)),
            self::thumb(2, \in_array(2, $shownNumbers, true)),
            self::thumb(3, \in_array(3, $shownNumbers, true)),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A screenshot strip shows exactly one screenshot.');

        ScreenshotPager::over($thumbs);
    }

    #[Test]
    public function overScreenshotsPagesByTheScreenshotQueryAndStopsAtBothEnds(): void
    {
        $first = ScreenshotPager::overScreenshots(1, 3);
        $middle = ScreenshotPager::overScreenshots(2, 3);
        $last = ScreenshotPager::overScreenshots(3, 3);
        $alone = ScreenshotPager::overScreenshots(1, 1);

        self::assertSame([null, ['screenshot' => 2], '1 of 3'], [$first->backParameters, $first->nextParameters, $first->positionLabel()]);
        self::assertSame([['screenshot' => 1], ['screenshot' => 3], '2 of 3'], [$middle->backParameters, $middle->nextParameters, $middle->positionLabel()]);
        self::assertSame([['screenshot' => 2], null, '3 of 3'], [$last->backParameters, $last->nextParameters, $last->positionLabel()]);
        self::assertSame([null, null, '1 of 1'], [$alone->backParameters, $alone->nextParameters, $alone->positionLabel()]);
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
        $this->expectExceptionMessage('The shown screenshot is one of the round\'s.');

        ScreenshotPager::overScreenshots($shownNumber, $count);
    }

    /**
     * @return non-empty-list<ScreenshotThumb>
     */
    private static function strip(int $count, int $shownNumber): array
    {
        $thumbs = [self::thumb(1, 1 === $shownNumber)];
        for ($number = 2; $number <= $count; ++$number) {
            $thumbs[] = self::thumb($number, $number === $shownNumber);
        }

        return $thumbs;
    }

    private static function thumb(int $number, bool $isShown): ScreenshotThumb
    {
        return new ScreenshotThumb($number, 'Screenshot '.$number, ['screenshot' => $number], null, '0 pins', 'screenshot-thumb__pins--none', null, $isShown);
    }
}
