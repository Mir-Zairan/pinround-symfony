<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Api;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaImageTest extends TestCase
{
    private const string MEDIA_ID = '0197a3c0-0000-7000-8000-0000000f5301';

    #[Test]
    public function eachStatusCarriesOnlyWhatItHas(): void
    {
        $pending = MediaImage::pending(self::MEDIA_ID);
        $ready = MediaImage::ready(self::MEDIA_ID, 'https://files.example.test/view.webp', 1280, 800);
        $rejected = MediaImage::rejected(self::MEDIA_ID, 'This image could not be read. Pick another screenshot.');

        self::assertSame([MediaImageStatus::Pending, null, null, null, null], [$pending->status, $pending->url, $pending->width, $pending->height, $pending->refusal]);
        self::assertSame([MediaImageStatus::Ready, 'https://files.example.test/view.webp', 1280, 800, null], [$ready->status, $ready->url, $ready->width, $ready->height, $ready->refusal]);
        self::assertSame([MediaImageStatus::Rejected, null, 'This image could not be read. Pick another screenshot.'], [$rejected->status, $rejected->url, $rejected->refusal]);
    }

    #[Test]
    public function aReadyPictureOfUnknownSizeIsTaken(): void
    {
        self::assertSame([null, null], [MediaImage::ready(self::MEDIA_ID, '/thumb.webp', null, null)->width, MediaImage::ready(self::MEDIA_ID, '/thumb.webp', null, null)->height]);
    }

    #[Test]
    public function aReadyPictureWithoutAUrlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A ready picture needs a URL.');

        MediaImage::ready(self::MEDIA_ID, '', 1280, 800);
    }

    /**
     * @return iterable<string, array{?int, ?int}>
     */
    public static function sizesBelowOnePixel(): iterable
    {
        yield 'zero wide' => [0, 800];
        yield 'negative high' => [1280, -1];
    }

    #[Test]
    #[DataProvider('sizesBelowOnePixel')]
    public function aReadyPictureBelowOnePixelIsRefused(?int $width, ?int $height): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A picture is at least one pixel wide and high.');

        MediaImage::ready(self::MEDIA_ID, '/view.webp', $width, $height);
    }

    #[Test]
    public function aRejectedPictureWithoutAReasonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A rejected picture needs the reason it was refused.');

        MediaImage::rejected(self::MEDIA_ID, " \n");
    }
}
