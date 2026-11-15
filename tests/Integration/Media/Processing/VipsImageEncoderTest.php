<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Processing;

use App\Media\Application\Processing\EncodedVariant;
use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\Processing\UndecodableImage;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\Media\Infrastructure\Processing\VipsImageEncoder;
use App\Tests\Fixtures\Media\ImageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * VipsImageEncoder with the real vips tool of the image's libvips-tools package. The JPEG and WebP originals are made
 * from an ImageFixtures PNG by that same tool, because the fixtures' own JPEG and WebP hold no decodable pixels. Each
 * variant is checked as CleanUpload checks it before storing: a still WebP of its stated size, exactly as long as its
 * RIFF container and not the original. Skipped where vips is not on the PATH; CI's integration job excludes the group.
 */
#[Group('libvips')]
final class VipsImageEncoderTest extends TestCase
{
    private const string GPS_MARKER = 'PINROUND-GPS-MARKER';

    private string $folder;

    protected function setUp(): void
    {
        if (null === new ExecutableFinder()->find('vips')) {
            self::markTestSkipped('The vips tool is not on the PATH; rebuild the app image (Dockerfile, libvips-tools) to run this test.');
        }
        $this->folder = \sprintf('%s/vips-encoder-integration-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->folder, 0o700);
    }

    protected function tearDown(): void
    {
        if (!isset($this->folder)) {
            return;
        }
        $names = scandir($this->folder);
        foreach (false === $names ? [] : $names as $name) {
            if ('.' !== $name && '..' !== $name) {
                unlink($this->folder.'/'.$name);
            }
        }
        rmdir($this->folder);
    }

    /**
     * @return iterable<string, array{ImageFormat}>
     */
    public static function formats(): iterable
    {
        yield 'png' => [ImageFormat::Png];
        yield 'jpeg' => [ImageFormat::Jpeg];
        yield 'webp' => [ImageFormat::Webp];
    }

    #[Test]
    #[DataProvider('formats')]
    public function eachFormatGivesAWebpViewAndThumbOfTheContractSizes(ImageFormat $format): void
    {
        $original = $this->original(ImageFixtures::png(800, 600), $format);

        $encoded = self::encoder()->encode($original, $format);

        self::assertCleanWebp($encoded->view, 800, 600, $original);
        self::assertCleanWebp($encoded->thumb, 480, 360, $original);
    }

    #[Test]
    public function aWidePictureIsScaledDownTo1920And480KeepingItsAspectRatio(): void
    {
        $original = ImageFixtures::png(3000, 1000);

        $encoded = self::encoder()->encode($original, ImageFormat::Png);

        self::assertCleanWebp($encoded->view, 1920, 640, $original);
        self::assertCleanWebp($encoded->thumb, 480, 160, $original);
    }

    #[Test]
    public function aTallPictureIsLimitedByItsWidthOnly(): void
    {
        $original = ImageFixtures::png(600, 3000);

        $encoded = self::encoder()->encode($original, ImageFormat::Png);

        self::assertCleanWebp($encoded->view, 600, 3000, $original);
        self::assertCleanWebp($encoded->thumb, 480, 2400, $original);
    }

    #[Test]
    public function aSmallPictureIsNotScaledUp(): void
    {
        $original = ImageFixtures::png(300, 200);

        $encoded = self::encoder()->encode($original, ImageFormat::Png);

        self::assertCleanWebp($encoded->view, 300, 200, $original);
        self::assertCleanWebp($encoded->thumb, 300, 200, $original);
    }

    #[Test]
    public function aPngWithAppendedScriptBytesComesOutWithoutThem(): void
    {
        $polyglot = ImageFixtures::withAppended(ImageFixtures::png(640, 480));

        $encoded = self::encoder()->encode($polyglot, ImageFormat::Png);

        foreach ([$encoded->view, $encoded->thumb] as $variant) {
            self::assertStringNotContainsString(ImageFixtures::SCRIPT_BYTES, $variant->bytes);
            self::assertStringNotContainsString('<?php', $variant->bytes);
        }
        self::assertCleanWebp($encoded->view, 640, 480, $polyglot);
        self::assertCleanWebp($encoded->thumb, 480, 360, $polyglot);
    }

    /**
     * @return iterable<string, array{string, ImageFormat}>
     */
    public static function brokenOriginals(): iterable
    {
        $png = ImageFixtures::png(600, 400);

        yield 'a png cut inside its image data' => [substr($png, 0, -20), ImageFormat::Png];
        yield 'a png whose header claims more rows than its data holds' => [ImageFixtures::pngClaiming(800, 600), ImageFormat::Png];
        yield 'a png given as a jpeg' => [$png, ImageFormat::Jpeg];
        yield 'a png given as a webp' => [$png, ImageFormat::Webp];
    }

    #[Test]
    #[DataProvider('brokenOriginals')]
    public function anOriginalThatDoesNotDecodeFullyAsItsFormatIsUndecodable(string $original, ImageFormat $format): void
    {
        $this->expectException(UndecodableImage::class);

        self::encoder()->encode($original, $format);
    }

    #[Test]
    public function aJpegCutShortIsUndecodable(): void
    {
        $jpeg = $this->original(ImageFixtures::png(800, 600), ImageFormat::Jpeg);

        $this->expectException(UndecodableImage::class);

        self::encoder()->encode(substr($jpeg, 0, intdiv(\strlen($jpeg), 2)), ImageFormat::Jpeg);
    }

    #[Test]
    public function exifAndGpsDataOfAJpegAreGone(): void
    {
        $jpeg = self::withGpsExif($this->original(ImageFixtures::png(800, 600), ImageFormat::Jpeg));
        self::assertStringContainsString(self::GPS_MARKER, $this->vipsHeaderField($jpeg, 'exif-ifd3-GPSMapDatum'), 'vips reads the GPS data of the original.');

        $encoded = self::encoder()->encode($jpeg, ImageFormat::Jpeg);

        foreach ([$encoded->view, $encoded->thumb] as $variant) {
            self::assertStringNotContainsString(self::GPS_MARKER, $variant->bytes);
            self::assertStringNotContainsString('Exif', $variant->bytes);
            self::assertStringNotContainsString('EXIF', $variant->bytes);
            self::assertStringNotContainsString('XMP ', $variant->bytes);
            self::assertStringNotContainsString('ICCP', $variant->bytes);
        }
        self::assertCleanWebp($encoded->view, 800, 600, $jpeg);
    }

    private static function encoder(): VipsImageEncoder
    {
        return new VipsImageEncoder(new PurePhpImageHeaderReader());
    }

    private static function assertCleanWebp(EncodedVariant $variant, int $width, int $height, string $original): void
    {
        $header = new PurePhpImageHeaderReader()->read(substr($variant->bytes, 0, ImageHeaderReader::LEADING_BYTES));
        self::assertSame(
            [ImageFormat::Webp, false, $width, $height, $width, $height],
            [$header->format, $header->isAnimated, $header->width, $header->height, $variant->width, $variant->height],
        );
        $riffSize = unpack('V', $variant->bytes, 4);
        $containerLength = false === $riffSize || !\is_int($riffSize[1] ?? null) ? null : 8 + $riffSize[1];
        self::assertSame(\strlen($variant->bytes), $containerLength, 'Nothing follows the WebP container.');
        self::assertNotSame($original, $variant->bytes);
    }

    /**
     * The PNG converted by vips into $format, so the original holds pixels a decoder accepts.
     */
    private function original(string $png, ImageFormat $format): string
    {
        if (ImageFormat::Png === $format) {
            return $png;
        }
        $source = $this->folder.'/source.png';
        $target = $this->folder.'/source.'.(ImageFormat::Jpeg === $format ? 'jpg' : 'webp');
        file_put_contents($source, $png);
        new Process(['vips', 'copy', $source, $target])->mustRun();

        return (string) file_get_contents($target);
    }

    private function vipsHeaderField(string $image, string $field): string
    {
        $path = $this->folder.'/header-of';
        file_put_contents($path, $image);

        return new Process(['vipsheader', '-f', $field, $path])->mustRun()->getOutput();
    }

    /**
     * $jpeg with an APP1 Exif segment after its SOI: IFD0 holds only the GPSInfo pointer, the GPS IFD a latitude
     * reference and a map datum that is GPS_MARKER, so the marker can be looked for in what comes out.
     */
    private static function withGpsExif(string $jpeg): string
    {
        $marker = self::GPS_MARKER."\x00";
        // IFD0 at 8 (2 + 12 + 4 bytes), the GPS IFD at 26 (2 + 2 × 12 + 4 bytes), its map datum at 56.
        $tiff = "II*\x00".pack('V', 8)
            .pack('v', 1).pack('vvVV', 0x8825, 4, 1, 26).pack('V', 0)
            .pack('v', 2).pack('vvV', 0x0001, 2, 2)."N\x00\x00\x00".pack('vvVV', 0x0012, 2, \strlen($marker), 56).pack('V', 0)
            .$marker;
        $exif = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', 2 + \strlen($exif)).$exif.substr($jpeg, 2);
    }
}
