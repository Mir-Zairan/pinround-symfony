<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\RoundPage;

use App\Review\UI\RoundPage\ScreenshotThumb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotThumbTest extends TestCase
{
    #[Test]
    public function aReadyPictureOfKnownWidthAndHeightHasItsSize(): void
    {
        self::assertTrue(self::thumb('https://storage.test/thumb/1', 480, 300)->hasImageSize());
    }

    /**
     * @return iterable<string, array{string|null, int|null, int|null}>
     */
    public static function picturesWithoutAWholeSize(): iterable
    {
        yield 'no picture yet' => [null, null, null];
        yield 'a picture of unknown size' => ['https://storage.test/thumb/1', null, null];
        yield 'only the width known' => ['https://storage.test/thumb/1', 480, null];
        yield 'only the height known' => ['https://storage.test/thumb/1', null, 300];
        yield 'a size but no picture' => [null, 480, 300];
    }

    #[Test]
    #[DataProvider('picturesWithoutAWholeSize')]
    public function aThumbPrintsNoSizeUnlessItHasAPictureAndBothSides(?string $imageUrl, ?int $imageWidth, ?int $imageHeight): void
    {
        self::assertFalse(self::thumb($imageUrl, $imageWidth, $imageHeight)->hasImageSize());
    }

    private static function thumb(?string $imageUrl, ?int $imageWidth, ?int $imageHeight): ScreenshotThumb
    {
        return new ScreenshotThumb(1, 'Screenshot 1', ['screenshot' => 1], $imageUrl, $imageWidth, $imageHeight, '0 pins', 'screenshot-thumb__pins--none', null, true);
    }
}
