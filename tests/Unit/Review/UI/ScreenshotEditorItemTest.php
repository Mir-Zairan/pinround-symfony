<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Media\Api\MediaImageStatus;
use App\Review\UI\PinView;
use App\Review\UI\ScreenshotEditorItem;
use App\Review\UI\ScreenshotFieldNames;
use App\Review\UI\ScreenshotView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotEditorItemTest extends TestCase
{
    #[Test]
    public function thePictureKeepsEverythingButTheTitleAndSummaryWhichTheEditorShowsAsFields(): void
    {
        $pins = [new PinView(1, 20.0, 30.0, 'total counted twice'), new PinView(2, 60.0, 40.0, '')];
        $view = new ScreenshotView('draft-shot-1', null, 800, 450, 'Screenshot 1 of the report', 'Cart', 'The total is wrong.', $pins, MediaImageStatus::Pending);

        $picture = new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportDraft(0))->picture;

        self::assertNull($picture->title);
        self::assertNull($picture->summary);
        self::assertSame('draft-shot-1', $picture->domId);
        self::assertSame([800, 450], [$picture->width, $picture->height]);
        self::assertSame('Screenshot 1 of the report', $picture->altText);
        self::assertSame($pins, $picture->pins);
        self::assertSame(MediaImageStatus::Pending, $picture->status);
    }

    #[Test]
    public function onlyAScreenshotWithARemoveFieldCanBeShownAsRemoved(): void
    {
        $view = new ScreenshotView('report-shot', '/media/shop-cart.png', 800, 450, 'Screenshot of the report');

        self::assertTrue(new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportDraft(0), isRemoved: true)->isRemoved);
        $this->expectExceptionObject(new \InvalidArgumentException('Only a screenshot with a remove field can show as removed.'));

        new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportForm(), isRemoved: true);
    }
}
