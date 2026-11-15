<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotTest extends TestCase
{
    private const string SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c01';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';

    /**
     * @return iterable<string, array{string}>
     */
    public static function mediaIdsThatAreNotUuidV7(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['screenshot.png'];
        yield 'version 4' => ['0197a3c0-0000-4000-8000-000000000d01'];
        yield 'variant bits 11xx' => ['0197a3c0-0000-7000-c000-000000000d01'];
        yield 'trailing newline' => ['0197a3c0-0000-7000-8000-000000000d01'."\n"];
        yield 'no hyphens' => ['0197a3c0000070008000000000000d01'];
    }

    #[Test]
    #[DataProvider('mediaIdsThatAreNotUuidV7')]
    public function itRefusesAMediaIdThatIsNotAUuidV7(string $mediaId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A screenshot needs the id of an uploaded picture.');

        Screenshot::of(new ScreenshotId(self::SCREENSHOT), $mediaId, 0, null, null, []);
    }

    #[Test]
    public function itKeepsTheMediaIdInLowercaseWhateverCaseItCameIn(): void
    {
        $screenshot = Screenshot::of(new ScreenshotId(self::SCREENSHOT), strtoupper(self::MEDIA), 0, null, null, []);

        self::assertSame(self::MEDIA, $screenshot->mediaId());
    }

    #[Test]
    public function itRefusesAPositionBelowZeroAndAcceptsZero(): void
    {
        self::assertSame(0, self::screenshot(0)->position());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A screenshot position starts at 0.');

        self::screenshot(-1);
    }

    #[Test]
    public function itAcceptsATitleOfExactlyTheCapInMultibyteCharacters(): void
    {
        $title = str_repeat('é', Screenshot::TITLE_MAX_LENGTH);

        self::assertSame($title, self::screenshot(0, $title)->title());
    }

    #[Test]
    public function itRefusesATitleOneCharacterOverTheCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A picture title holds at most 120 characters.');

        self::screenshot(0, str_repeat('é', Screenshot::TITLE_MAX_LENGTH + 1));
    }

    #[Test]
    public function itAcceptsASummaryOfExactlyTheCapInMultibyteCharacters(): void
    {
        $summary = str_repeat('é', Screenshot::SUMMARY_MAX_LENGTH);

        self::assertSame($summary, self::screenshot(0, null, $summary)->summary());
    }

    #[Test]
    public function itRefusesASummaryOneCharacterOverTheCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A picture summary holds at most 600 characters.');

        self::screenshot(0, null, str_repeat('é', Screenshot::SUMMARY_MAX_LENGTH + 1));
    }

    #[Test]
    public function itTrimsTitleAndSummaryBeforeCountingThem(): void
    {
        $title = str_repeat('a', Screenshot::TITLE_MAX_LENGTH);
        $summary = str_repeat('b', Screenshot::SUMMARY_MAX_LENGTH);

        $screenshot = self::screenshot(0, "  \r\n".$title."\t ", " \n".$summary.' ');

        self::assertSame($title, $screenshot->title());
        self::assertSame($summary, $screenshot->summary());
    }

    #[Test]
    public function aBlankTitleOrSummaryIsNoneNotAnEmptyString(): void
    {
        $screenshot = self::screenshot(0, " \r\n\t ", '');

        self::assertNull($screenshot->title());
        self::assertNull($screenshot->summary());
        self::assertNull(self::screenshot(0, null, null)->title());
    }

    #[Test]
    public function itHoldsExactlyTheMaximumNumberOfPinsAndRefusesOneMore(): void
    {
        $pins = [];
        for ($number = 1; $number <= Screenshot::MAX_PINS; ++$number) {
            $pins[] = self::pin($number);
        }
        self::assertCount(100, self::screenshot(0, null, null, $pins)->pins());

        $pins[] = self::pin(Screenshot::MAX_PINS + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A picture holds at most 100 pins.');

        self::screenshot(0, null, null, $pins);
    }

    #[Test]
    public function itRefusesTwoPinsWithOneNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Pin 2 appears twice on one screenshot.');

        self::screenshot(0, null, null, [self::pin(1), self::pin(2), self::pin(2)]);
    }

    #[Test]
    public function itKeepsThePinsInTheOrderOfTheirNumbers(): void
    {
        $screenshot = self::screenshot(0, null, null, [self::pin(3), self::pin(1), self::pin(2)]);

        self::assertSame([1, 2, 3], array_map(static fn (Pin $pin): int => $pin->number(), $screenshot->pins()));
    }

    #[Test]
    public function aNewScreenshotHasNoCompareLabelAndNoVerdict(): void
    {
        $screenshot = self::screenshot(4);

        self::assertNull($screenshot->variantLabel());
        self::assertNull($screenshot->verdict());
        self::assertSame(self::SCREENSHOT, $screenshot->id()->toString());
        self::assertSame(4, $screenshot->position());
    }

    /**
     * @param list<Pin> $pins
     */
    private static function screenshot(int $position, ?string $title = null, ?string $summary = null, array $pins = []): Screenshot
    {
        return Screenshot::of(new ScreenshotId(self::SCREENSHOT), self::MEDIA, $position, $title, $summary, $pins);
    }

    private static function pin(int $number): Pin
    {
        return Pin::placedBy(new PinId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xA00 + $number)), $number, 10.0, 10.0, '', new UserId(self::MIA));
    }
}
