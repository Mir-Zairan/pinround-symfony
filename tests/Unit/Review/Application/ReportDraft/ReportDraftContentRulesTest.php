<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\ReportDraft;

use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\ReportDraft\ReportDraftContentRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportDraftContentRulesTest extends TestCase
{
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f7101';

    #[Test]
    public function contentAtEveryCapIsKeptTrimmedWithItsMediaIdInLowercase(): void
    {
        $content = new ReportDraftContent(
            '  '.str_repeat('é', 200)." \r\n",
            str_repeat('a', 50000),
            [
                new ScreenshotWithPins(strtoupper(self::MEDIA), ' '.str_repeat('t', 120).' ', str_repeat('s', 600), [new PlacedPin(0.0, 100.0, ' '.str_repeat('n', 1000).' '), new PlacedPin(12.345, 99.994, '')]),
                ...array_map(static fn (int $index): ScreenshotWithPins => new ScreenshotWithPins(\sprintf('0197a3c0-0000-7000-8000-0000000f72%02d', $index), "  \t", '', []), range(1, 9)),
            ],
        );

        $checked = ReportDraftContentRules::checked($content);

        self::assertSame(str_repeat('é', 200), $checked->title);
        self::assertSame(50000, mb_strlen($checked->detail));
        self::assertCount(10, $checked->screenshots);
        $first = $checked->screenshots[0];
        self::assertSame([self::MEDIA, str_repeat('t', 120), str_repeat('s', 600)], [$first->mediaId, $first->title, $first->summary]);
        self::assertEquals([new PlacedPin(0.0, 100.0, str_repeat('n', 1000)), new PlacedPin(12.35, 99.99, '')], $first->pins);
        self::assertSame([null, null], [$checked->screenshots[1]->title, $checked->screenshots[1]->summary], 'A blank picture title or summary is none.');
    }

    /**
     * @return iterable<string, array{ReportDraftContent, string}>
     */
    public static function brokenContent(): iterable
    {
        yield 'a blank title' => [new ReportDraftContent(" \n\t", '', []), ReportDraftRefused::TITLE_BLANK];
        yield 'a title of 201 characters' => [new ReportDraftContent(str_repeat('é', 201), '', []), ReportDraftRefused::TITLE_TOO_LONG];
        yield 'a detail of 50,001 characters' => [new ReportDraftContent('Total', str_repeat('a', 50001), []), ReportDraftRefused::DETAIL_TOO_LONG];
        yield '11 pictures' => [new ReportDraftContent('Total', '', array_map(static fn (int $index): ScreenshotWithPins => self::picture(mediaId: \sprintf('0197a3c0-0000-7000-8000-0000000f72%02d', $index)), range(1, 11))), ReportDraftRefused::TOO_MANY_SCREENSHOTS];
        yield 'a picture title of 121 characters' => [new ReportDraftContent('Total', '', [self::picture(str_repeat('t', 121))]), ReportDraftRefused::PICTURE_TITLE_TOO_LONG];
        yield 'a summary of 601 characters' => [new ReportDraftContent('Total', '', [self::picture(null, str_repeat('s', 601))]), ReportDraftRefused::PICTURE_SUMMARY_TOO_LONG];
        yield 'a pin note of 1,001 characters' => [new ReportDraftContent('Total', '', [self::picture(pins: [new PlacedPin(1.0, 1.0, str_repeat('n', 1001))])]), ReportDraftRefused::PIN_NOTE_TOO_LONG];
        yield 'a pin left of the picture' => [new ReportDraftContent('Total', '', [self::picture(pins: [new PlacedPin(-0.01, 1.0, '')])]), ReportDraftRefused::PIN_OUTSIDE];
        yield 'a pin below the picture' => [new ReportDraftContent('Total', '', [self::picture(pins: [new PlacedPin(1.0, 100.01, '')])]), ReportDraftRefused::PIN_OUTSIDE];
        yield 'a pin at no number' => [new ReportDraftContent('Total', '', [self::picture(pins: [new PlacedPin(\NAN, 1.0, '')])]), ReportDraftRefused::PIN_OUTSIDE];
        yield '101 pins' => [new ReportDraftContent('Total', '', [self::picture(pins: array_fill(0, 101, new PlacedPin(1.0, 1.0, '')))]), ReportDraftRefused::TOO_MANY_PINS];
        yield 'the same picture twice, in another case' => [new ReportDraftContent('Total', '', [self::picture(), self::picture(mediaId: strtoupper(self::MEDIA))]), ReportDraftRefused::SAME_PICTURE_TWICE];
        yield 'a media id with a trailing newline' => [new ReportDraftContent('Total', '', [self::picture(mediaId: self::MEDIA."\n")]), RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a media id that is no uuid' => [new ReportDraftContent('Total', '', [self::picture(mediaId: '../etc/passwd')]), RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('brokenContent')]
    public function contentBreakingARuleIsRefusedWithItsPlainMessage(ReportDraftContent $content, string $expectedReason): void
    {
        try {
            ReportDraftContentRules::checked($content);
            self::fail('The content was kept.');
        } catch (ReportDraftRefused $refused) {
            self::assertSame($expectedReason, $refused->getMessage());
            self::assertFalse($refused->isNotFound());
        }
    }

    /**
     * @param list<PlacedPin> $pins
     */
    private static function picture(?string $title = null, ?string $summary = null, array $pins = [], string $mediaId = self::MEDIA): ScreenshotWithPins
    {
        return new ScreenshotWithPins($mediaId, $title, $summary, $pins);
    }
}
