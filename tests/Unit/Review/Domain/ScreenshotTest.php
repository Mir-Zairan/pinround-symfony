<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\Review\Domain\VariantLabel;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotTest extends TestCase
{
    private const string SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c01';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';
    private const string FIRST_TRY = '0197a3c0-0000-7000-8000-000000000c00';

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

    #[Test]
    public function withTextTrimsAndReplacesTheTextAndKeepsPinsVerdictAndLabel(): void
    {
        $original = Screenshot::restored(new ScreenshotId(self::SCREENSHOT), self::MEDIA, 3, 'Old', 'Old summary', [self::pin(2)], VariantLabel::After, ScreenshotVerdict::NotRight, new ScreenshotId(self::FIRST_TRY));

        $changed = $original->withText("  New title\n", ' New summary ');

        self::assertSame('New title', $changed->title());
        self::assertSame('New summary', $changed->summary());
        self::assertSame(3, $changed->position());
        self::assertSame(self::MEDIA, $changed->mediaId());
        self::assertSame([2], array_map(static fn (Pin $pin): int => $pin->number(), $changed->pins()));
        self::assertSame(VariantLabel::After, $changed->variantLabel());
        self::assertSame(ScreenshotVerdict::NotRight, $changed->verdict());
        self::assertSame(self::FIRST_TRY, $changed->retakeOf()?->toString());
        self::assertSame('Old', $original->title());
    }

    #[Test]
    public function withTextRefusesATitleOrSummaryOverTheCapAndAcceptsOneAtTheCap(): void
    {
        $screenshot = self::screenshot(0);
        self::assertSame(120, mb_strlen((string) $screenshot->withText(str_repeat('é', 120), null)->title()));

        try {
            $screenshot->withText(str_repeat('é', 121), null);
            self::fail('A title over the cap was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A picture title holds at most 120 characters.', $refused->getMessage());
        }

        try {
            $screenshot->withText(null, str_repeat('é', 601));
            self::fail('A summary over the cap was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A picture summary holds at most 600 characters.', $refused->getMessage());
        }
    }

    #[Test]
    public function withPinsReplacesThePinsInNumberOrderAndKeepsTextAndVerdict(): void
    {
        $original = Screenshot::restored(new ScreenshotId(self::SCREENSHOT), self::MEDIA, 0, 'Title', 'Summary', [self::pin(1)], null, ScreenshotVerdict::LooksRight, new ScreenshotId(self::FIRST_TRY));

        $changed = $original->withPins([self::pin(4), self::pin(2)]);

        self::assertSame([2, 4], array_map(static fn (Pin $pin): int => $pin->number(), $changed->pins()));
        self::assertSame('Title', $changed->title());
        self::assertSame('Summary', $changed->summary());
        self::assertSame(ScreenshotVerdict::LooksRight, $changed->verdict());
        self::assertSame(self::FIRST_TRY, $changed->retakeOf()?->toString());
        self::assertSame([1], array_map(static fn (Pin $pin): int => $pin->number(), $original->pins()));
        self::assertSame([], $original->withPins([])->pins());
    }

    #[Test]
    public function withPinsRefusesTwoPinsWithOneNumberAndMoreThanTheCap(): void
    {
        $screenshot = self::screenshot(0);

        try {
            $screenshot->withPins([self::pin(1), self::pin(1)]);
            self::fail('Two pins with one number were accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Pin 1 appears twice on one screenshot.', $refused->getMessage());
        }

        $tooMany = [];
        for ($number = 1; $number <= Screenshot::MAX_PINS + 1; ++$number) {
            $tooMany[] = self::pin($number);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A picture holds at most 100 pins.');

        $screenshot->withPins($tooMany);
    }

    #[Test]
    public function withVerdictSetsAndClearsTheVerdictAndKeepsTheRest(): void
    {
        $original = Screenshot::of(new ScreenshotId(self::SCREENSHOT), self::MEDIA, 2, 'Title', 'Summary', [self::pin(1)]);

        $judged = $original->withVerdict(ScreenshotVerdict::NotRight);

        self::assertSame(ScreenshotVerdict::NotRight, $judged->verdict());
        self::assertSame('Title', $judged->title());
        self::assertCount(1, $judged->pins());
        self::assertSame(2, $judged->position());
        self::assertNull($original->verdict());
        self::assertNull($judged->withVerdict(null)->verdict());
    }

    #[Test]
    public function aRestoredScreenshotKeepsItsLabelAndVerdictAndStillObeysTheRulesOfOf(): void
    {
        $restored = Screenshot::restored(new ScreenshotId(self::SCREENSHOT), strtoupper(self::MEDIA), 1, ' Title ', null, [self::pin(2), self::pin(1)], VariantLabel::Before, ScreenshotVerdict::LooksRight, new ScreenshotId(self::FIRST_TRY));

        self::assertSame(VariantLabel::Before, $restored->variantLabel());
        self::assertSame(ScreenshotVerdict::LooksRight, $restored->verdict());
        self::assertSame(self::MEDIA, $restored->mediaId());
        self::assertSame('Title', $restored->title());
        self::assertSame([1, 2], array_map(static fn (Pin $pin): int => $pin->number(), $restored->pins()));
        self::assertSame(self::FIRST_TRY, $restored->retakeOf()?->toString());
        self::assertSame(self::FIRST_TRY, $restored->firstTryId()->toString());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A screenshot needs the id of an uploaded picture.');

        Screenshot::restored(new ScreenshotId(self::SCREENSHOT), 'screenshot.png', 1, null, null, [], null, null, null);
    }

    #[Test]
    public function aNewScreenshotIsTheFirstTryOfItsOwnChain(): void
    {
        $screenshot = self::screenshot(0);

        self::assertNull($screenshot->retakeOf());
        self::assertSame(self::SCREENSHOT, $screenshot->firstTryId()->toString());
    }

    #[Test]
    public function asATryItPointsAtTheFirstTryAndKeepsEverythingElseAndAsAFirstTryAgainItPointsNowhere(): void
    {
        $original = Screenshot::restored(new ScreenshotId(self::SCREENSHOT), self::MEDIA, 3, 'Title', 'Summary', [self::pin(1)], VariantLabel::After, ScreenshotVerdict::NotRight, null);

        $try = $original->asTryOf(new ScreenshotId(self::FIRST_TRY));

        self::assertSame(self::FIRST_TRY, $try->retakeOf()?->toString());
        self::assertSame(self::FIRST_TRY, $try->firstTryId()->toString());
        self::assertSame([self::SCREENSHOT, self::MEDIA, 3, 'Title', 'Summary', [1], VariantLabel::After, ScreenshotVerdict::NotRight], [$try->id()->toString(), $try->mediaId(), $try->position(), $try->title(), $try->summary(), array_map(static fn (Pin $pin): int => $pin->number(), $try->pins()), $try->variantLabel(), $try->verdict()]);
        self::assertNull($original->retakeOf());
        self::assertNull($try->asFirstTry()->retakeOf());
        self::assertSame(self::SCREENSHOT, $try->asFirstTry()->firstTryId()->toString());
        self::assertSame(self::FIRST_TRY, $try->withVerdict(ScreenshotVerdict::LooksRight)->retakeOf()?->toString());
    }

    #[Test]
    public function aScreenshotIsNeverARetakeOfItself(): void
    {
        try {
            self::screenshot(0)->asTryOf(new ScreenshotId(strtoupper(self::SCREENSHOT)));
            self::fail('A screenshot became a retake of itself.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A screenshot cannot be a retake of itself.', $refused->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A screenshot cannot be a retake of itself.');

        Screenshot::restored(new ScreenshotId(self::SCREENSHOT), self::MEDIA, 0, null, null, [], null, null, new ScreenshotId(self::SCREENSHOT));
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
