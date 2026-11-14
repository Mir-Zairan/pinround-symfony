<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\UI\Component;

use App\Notification\UI\Component\PingSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingSheetTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}> the Now line and the draft
     */
    public static function nowLinesAndDrafts(): iterable
    {
        yield 'a Now line with its full stop' => ['Lena plans it.', 'Hi Lena, could you look at SHOP-1? Now: Lena plans it.'];
        yield 'a Now line without an end mark' => ['Code review', 'Hi Lena, could you look at SHOP-1? Now: Code review.'];
        yield 'a Now line that asks' => ['Who checks it?', 'Hi Lena, could you look at SHOP-1? Now: Who checks it?'];
        yield 'a Now line with spaces around it' => ["  Omar fixes it. \n", 'Hi Lena, could you look at SHOP-1? Now: Omar fixes it.'];
        yield 'no Now line, a ticket no team owns' => [null, 'Hi Lena, could you look at SHOP-1?'];
        yield 'an empty Now line' => ['  ', 'Hi Lena, could you look at SHOP-1?'];
    }

    #[Test]
    #[DataProvider('nowLinesAndDrafts')]
    public function theDraftQuotesTheNowLineWithOneEndMark(?string $nowText, string $expectedDraft): void
    {
        $sheet = $this->sheetFor('Lena', 4);
        $sheet->nowText = $nowText;

        self::assertSame($expectedDraft, $sheet->draftMessage());
    }

    #[Test]
    public function theNoteNamesTheLimitInHours(): void
    {
        self::assertSame('Lena gets one notification. You can ping again after 8 hours (set by your admin).', $this->sheetFor('Lena', 8)->limitNote());
    }

    #[Test]
    public function theNoteSaysOneHourForALimitOfOne(): void
    {
        self::assertSame('Lena gets one notification. You can ping again after 1 hour (set by your admin).', $this->sheetFor('Lena', 1)->limitNote());
    }

    /**
     * The texts read only the props, so the sheet is built without its services.
     */
    private function sheetFor(string $firstName, int $limitHours): PingSheet
    {
        $sheet = new \ReflectionClass(PingSheet::class)->newInstanceWithoutConstructor();
        $sheet->ticketKey = 'SHOP-1';
        $sheet->toFirstName = $firstName;
        $sheet->limitHours = $limitHours;

        return $sheet;
    }
}
