<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Processing;

use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\Processing\UnknownImageFormat;
use App\Media\Application\Processing\UnreadableImageHeader;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\Tests\Fixtures\Media\ImageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PurePhpImageHeaderReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ImageFormat, int, int}>
     */
    public static function stillPictures(): iterable
    {
        yield 'png' => [ImageFixtures::png(64, 48), ImageFormat::Png, 64, 48];
        yield 'jpeg' => [ImageFixtures::jpeg(800, 600), ImageFormat::Jpeg, 800, 600];
        yield 'webp lossy (VP8)' => [ImageFixtures::webpLossy(1280, 720), ImageFormat::Webp, 1280, 720];
        yield 'webp lossless (VP8L)' => [ImageFixtures::webpLossless(1921, 1081), ImageFormat::Webp, 1921, 1081];
        yield 'webp lossless at its 14-bit limit' => [ImageFixtures::webpLossless(16384, 1), ImageFormat::Webp, 16384, 1];
        yield 'webp extended (VP8X)' => [ImageFixtures::webpExtended(3000, 2000), ImageFormat::Webp, 3000, 2000];
    }

    #[Test]
    #[DataProvider('stillPictures')]
    public function theFormatWidthAndHeightAreReadFromTheHeaderAndThePictureIsNotAnimated(string $bytes, ImageFormat $format, int $width, int $height): void
    {
        $header = self::reader()->read($bytes);

        self::assertSame([$format, $width, $height, false], [$header->format, $header->width, $header->height, $header->isAnimated]);
    }

    /**
     * @return iterable<string, array{string, ImageFormat, int, int}>
     */
    public static function pictureBombs(): iterable
    {
        yield 'png' => [ImageFixtures::pngClaiming(50_000, 50_000), ImageFormat::Png, 50_000, 50_000];
        yield 'jpeg at its 16-bit limit' => [ImageFixtures::jpeg(65_535, 65_535), ImageFormat::Jpeg, 65_535, 65_535];
        yield 'webp extended at its 24-bit limit' => [ImageFixtures::webpExtended(16_777_216, 16_777_216), ImageFormat::Webp, 16_777_216, 16_777_216];
    }

    #[Test]
    #[DataProvider('pictureBombs')]
    public function aHeaderClaimingAHugeSizeIsReadAsItStatesWithoutDecodingAnything(string $bytes, ImageFormat $format, int $width, int $height): void
    {
        $header = self::reader()->read($bytes);

        self::assertSame([$format, $width, $height], [$header->format, $header->width, $header->height]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function animatedPictures(): iterable
    {
        yield 'apng' => [ImageFixtures::animatedPng(32, 32)];
        yield 'webp with the animation flag' => [ImageFixtures::webpExtended(32, 32, animationFlag: true)];
        yield 'webp with animation chunks and no flag' => [ImageFixtures::webpExtended(32, 32, animationChunks: true)];
        yield 'webp with both' => [ImageFixtures::webpExtended(32, 32, animationFlag: true, animationChunks: true)];
    }

    #[Test]
    #[DataProvider('animatedPictures')]
    public function anAnimatedPictureIsReportedAnimatedWithItsSize(string $bytes): void
    {
        $header = self::reader()->read($bytes);

        self::assertTrue($header->isAnimated);
        self::assertSame([32, 32], [$header->width, $header->height]);
    }

    #[Test]
    public function aStillWebpWithAnotherChunkBeforeItsBitstreamIsNotAnimated(): void
    {
        $iccProfile = 'ICCP'.pack('V', 4).'abcd';
        $still = ImageFixtures::webpExtended(10, 20);
        $withProfile = substr($still, 0, 30).$iccProfile.substr($still, 30);
        $withProfile = substr($withProfile, 0, 4).pack('V', \strlen($withProfile) - 8).substr($withProfile, 8);

        $header = self::reader()->read($withProfile);

        self::assertSame([10, 20, false], [$header->width, $header->height, $header->isAnimated]);
    }

    #[Test]
    public function aLossyWebpSizeIgnoresTheTwoScalingBitsOnTopOfEachSide(): void
    {
        $scaled = ImageFixtures::webpLossy(640 | 0xC000, 480 | 0x4000);

        $header = self::reader()->read($scaled);

        self::assertSame([640, 480], [$header->width, $header->height]);
    }

    #[Test]
    public function aJpegWithFillBytesAndALongSegmentBeforeItsFrameHeaderIsStillRead(): void
    {
        $jpeg = ImageFixtures::jpeg(321, 123);
        $exif = "\xFF\xE1".pack('n', 2 + 5_000).str_repeat('e', 5_000);
        $withFill = "\xFF\xD8\xFF\xFF\xFF".substr($exif, 1).substr($jpeg, 2);

        $header = self::reader()->read($withFill);

        self::assertSame([321, 123], [$header->width, $header->height]);
    }

    #[Test]
    public function aPngWithMetadataChunksBeforeItsImageDataIsRead(): void
    {
        $png = ImageFixtures::png(10, 11);
        $text = pack('N', 9).'tEXtTitle'."\x00".'xyz'.pack('N', 0);
        $withText = substr($png, 0, 33).$text.substr($png, 33);

        $header = self::reader()->read($withText);

        self::assertSame([ImageFormat::Png, 10, 11, false], [$header->format, $header->width, $header->height, $header->isAnimated]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bytesWithNoKnownSignature(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte' => ["\x89"];
        yield 'a gif' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;"];
        yield 'a bmp' => ["BM\x00\x00\x00\x00"];
        yield 'text with a byte order mark' => ["\xEF\xBB\xBF<html>\r\n"];
        yield 'a png signature cut short' => ["\x89PNG\r\n\x1a"];
        yield 'a png signature with a bad line ending' => ["\x89PNG\n\x1a\n".str_repeat("\x00", 40)];
        yield 'two bytes of a jpeg signature' => ["\xFF\xD8"];
        yield 'a riff that is not webp' => ['RIFF'.pack('V', 4).'WAVEfmt '];
        yield 'a riff cut before its form' => ['RIFF'.pack('V', 4)];
        yield 'a script' => ['<?php echo 1;'];
    }

    #[Test]
    #[DataProvider('bytesWithNoKnownSignature')]
    public function bytesWithNoPngJpegOrWebpSignatureAreAnUnknownFormat(string $bytes): void
    {
        $this->expectException(UnknownImageFormat::class);

        self::reader()->read($bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenHeaders(): iterable
    {
        $png = ImageFixtures::png(40, 30);
        $jpeg = ImageFixtures::jpeg(40, 30);
        $webpLossy = ImageFixtures::webpLossy(40, 30);
        $webpLossless = ImageFixtures::webpLossless(40, 30);
        $webpExtended = ImageFixtures::webpExtended(40, 30);

        yield 'a png with only its signature' => [substr($png, 0, 8)];
        yield 'a png cut inside its size' => [substr($png, 0, 20)];
        yield 'a png cut after its header chunk' => [substr($png, 0, 33)];
        yield 'a png cut inside the next chunk header' => [substr($png, 0, 37)];
        yield 'a png whose first chunk is not IHDR' => [substr_replace($png, 'IDAT', 12, 4)];
        yield 'a png whose IHDR is not 13 bytes' => [substr_replace($png, pack('N', 12), 8, 4)];
        yield 'a png of width 0' => [substr_replace($png, pack('N', 0), 16, 4)];
        yield 'a png of height 0' => [substr_replace($png, pack('N', 0), 20, 4)];
        yield 'a png that ends before any image data' => [substr($png, 0, 33).pack('N', 0).'IEND'.pack('N', 0)];
        yield 'a png with a chunk length past 31 bits' => [substr($png, 0, 33).pack('N', 0x80000000).'tEXt'.str_repeat('a', 16)];
        yield 'a png with a chunk that runs past the bytes' => [substr($png, 0, 33).pack('N', 5000).'tEXt'.str_repeat('a', 16)];
        yield 'a jpeg with only its signature' => [substr($jpeg, 0, 3)];
        yield 'a jpeg cut inside its segment length' => [substr($jpeg, 0, 5)];
        yield 'a jpeg cut inside its frame header' => [substr($jpeg, 0, 28)];
        yield 'a jpeg with bytes between its segments' => ["\xFF\xD8\xFF\xE0\x00\x04abx\xFF\xC0"];
        yield 'a jpeg with a scan before any frame header' => ["\xFF\xD8\xFF\xDA".pack('n', 8)."\x01\x01\x00\x00\x3F\x00\xFF\xD9"];
        yield 'a jpeg that ends before any frame header' => ["\xFF\xD8\xFF\xD9"];
        yield 'a jpeg with a segment shorter than its length field' => ["\xFF\xD8\xFF\xE0\x00\x01\xFF\xC0"];
        yield 'a jpeg with a segment that runs past the bytes' => ["\xFF\xD8\xFF\xE0".pack('n', 60_000).'abc'];
        yield 'a jpeg of width 0' => [substr_replace($jpeg, pack('n', 0), 2 + 18 + 7, 2)];
        yield 'a jpeg of height 0' => [substr_replace($jpeg, pack('n', 0), 2 + 18 + 5, 2)];
        yield 'a webp cut after its form' => [substr($webpLossy, 0, 12)];
        yield 'a webp cut inside its first chunk header' => [substr($webpLossy, 0, 18)];
        yield 'a lossy webp cut inside its size' => [substr($webpLossy, 0, 27)];
        yield 'a lossy webp without the key frame start code' => [substr_replace($webpLossy, 'abc', 23, 3)];
        yield 'a lossless webp cut inside its size' => [substr($webpLossless, 0, 22)];
        yield 'a lossless webp without its signature byte' => [substr_replace($webpLossless, "\x00", 20, 1)];
        yield 'a webp with another first chunk' => [substr_replace($webpLossy, 'EXIF', 12, 4)];
        yield 'an extended webp cut inside its canvas size' => [substr($webpExtended, 0, 26)];
        yield 'an extended webp with no bitstream within the bytes' => [substr($webpExtended, 0, 30)];
        yield 'an extended webp whose chunk size runs past the bytes' => [substr($webpExtended, 0, 30).'ICCP'.pack('V', 4_000_000_000).'abcd'];
    }

    #[Test]
    #[DataProvider('brokenHeaders')]
    public function aKnownSignatureWithABrokenOrCutHeaderIsUnreadableNeverGuessed(string $bytes): void
    {
        $this->expectException(UnreadableImageHeader::class);

        self::reader()->read($bytes);
    }

    #[Test]
    public function everyCutOfEachPictureEitherReadsItsSizeOrIsRefusedWithAHeaderException(): void
    {
        $pictures = [ImageFixtures::png(40, 30), ImageFixtures::jpeg(40, 30), ImageFixtures::webpLossy(40, 30), ImageFixtures::webpLossless(40, 30), ImageFixtures::webpExtended(40, 30), ImageFixtures::animatedPng(40, 30)];
        $refusals = 0;

        foreach ($pictures as $picture) {
            for ($length = 0; $length <= \strlen($picture); ++$length) {
                try {
                    $header = self::reader()->read(substr($picture, 0, $length));
                    self::assertSame([40, 30], [$header->width, $header->height]);
                } catch (UnknownImageFormat|UnreadableImageHeader) {
                    ++$refusals;
                }
            }
        }

        self::assertGreaterThan(100, $refusals, 'No cut was refused, so nothing was checked.');
    }

    #[Test]
    public function theLeadingBytesLimitIsOneMegabyteSoAHeaderBehindLargeMetadataIsStillWithinIt(): void
    {
        $metadata = str_repeat('m', 900_000);
        $png = ImageFixtures::png(7, 9);
        $withMetadata = substr($png, 0, 33).pack('N', \strlen($metadata)).'tEXt'.$metadata.pack('N', 0).substr($png, 33);

        $header = self::reader()->read(substr($withMetadata, 0, ImageHeaderReader::LEADING_BYTES));

        self::assertSame([7, 9], [$header->width, $header->height]);
    }

    #[Test]
    public function anImageDataChunkPastTheLeadingBytesIsUnreadableNotAssumedStill(): void
    {
        $metadata = str_repeat('m', ImageHeaderReader::LEADING_BYTES);
        $png = ImageFixtures::png(7, 9);
        $withMetadata = substr($png, 0, 33).pack('N', \strlen($metadata)).'tEXt'.$metadata.pack('N', 0).substr($png, 33);

        $this->expectException(UnreadableImageHeader::class);

        self::reader()->read(substr($withMetadata, 0, ImageHeaderReader::LEADING_BYTES));
    }

    private static function reader(): PurePhpImageHeaderReader
    {
        return new PurePhpImageHeaderReader();
    }
}
