<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Review\UI\ScreenshotFieldNames;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotFieldNamesTest extends TestCase
{
    #[Test]
    public function theReportFormNamesAreTheOnesNewTicketReportReads(): void
    {
        $names = ScreenshotFieldNames::ofReportForm();

        self::assertSame('screenshot_title', $names->title);
        self::assertSame('screenshot_summary', $names->summary);
        self::assertNull($names->remove);
        self::assertTrue($names->placesPins());
    }

    #[Test]
    public function aDraftScreenshotIsNamedByItsIndexAndItsPinNotesByTheirPosition(): void
    {
        $names = ScreenshotFieldNames::ofReportDraft(2);

        self::assertSame('screenshots[2][title]', $names->title);
        self::assertSame('screenshots[2][summary]', $names->summary);
        self::assertSame('screenshots[2][remove]', $names->remove);
        self::assertFalse($names->placesPins());
        self::assertSame('screenshots[2][pins][0]', $names->pinNoteNameOf(0));
        self::assertSame('screenshots[2][pins][3]', $names->pinNoteNameOf(3));
    }

    #[Test]
    public function aNegativeDraftIndexIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A draft screenshot index starts at 0.'));

        ScreenshotFieldNames::ofReportDraft(-1);
    }

    #[Test]
    public function aNegativePinPositionIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A pin position starts at 0.'));

        ScreenshotFieldNames::ofReportDraft(0)->pinNoteNameOf(-1);
    }

    #[Test]
    public function theReportFormHasNoPinNoteNamesOfItsOwnAsThePinPlacerNamesThem(): void
    {
        $this->expectExceptionObject(new \LogicException('The pin-placer names these pins pins[n][…].'));

        ScreenshotFieldNames::ofReportForm()->pinNoteNameOf(0);
    }
}
