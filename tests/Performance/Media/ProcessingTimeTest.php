<?php

declare(strict_types=1);

namespace App\Tests\Performance\Media;

use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Domain\UploadPolicy;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\Media\Infrastructure\Processing\VipsImageEncoder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * NFR-PERF-08: a 15 MB picture is processed in at most 10 s at the 95th percentile. Times what CleanUpload does with
 * the bytes, with the real vips tool: the header read, the sha256 and the view and thumb encode; the bucket's HEAD, GET
 * and PUTs are not in it. The picture is RGB noise in stored (uncompressed) deflate blocks, so it is just under 15 MB
 * and nothing in it makes WebP's work easier.
 *
 * No test suite of phpunit.dist.xml reaches tests/Performance, so it runs only when named:
 * vendor/bin/phpunit tests/Performance/Media/ProcessingTimeTest.php. It prints the times to STDERR. Skipped where vips
 * is not on the PATH, as VipsImageEncoderTest is.
 */
#[Group('performance')]
final class ProcessingTimeTest extends TestCase
{
    private const int RUNS = 20;
    private const float P95_LIMIT_SECONDS = 10.0;

    // 2,560 × 2,040 RGB is 15,669,240 bytes of rows and 5.2 megapixels, inside every UploadPolicy limit.
    private const int WIDTH = 2_560;
    private const int HEIGHT = 2_040;

    protected function setUp(): void
    {
        if (null === new ExecutableFinder()->find('vips')) {
            self::markTestSkipped('The vips tool is not on the PATH; rebuild the app image (Dockerfile, libvips-tools) to run this test.');
        }
    }

    #[Test]
    public function aFifteenMegabytePictureIsProcessedWithinTenSecondsAtTheNinetyFifthPercentile(): void
    {
        $original = self::noisePng();
        self::assertGreaterThan(UploadPolicy::MAX_BYTES - 100_000, \strlen($original), 'The picture is not close to 15 MB.');
        self::assertLessThanOrEqual(UploadPolicy::MAX_BYTES, \strlen($original), 'The picture is above the upload limit.');
        $headerReader = new PurePhpImageHeaderReader();
        $encoder = new VipsImageEncoder($headerReader);

        $seconds = [];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $startedNs = hrtime(true);
            $headerReader->read(substr($original, 0, ImageHeaderReader::LEADING_BYTES));
            hash('sha256', $original);
            $encoded = $encoder->encode($original, ImageFormat::Png);
            $seconds[] = (hrtime(true) - $startedNs) / 1e9;
            self::assertSame([1920, 480], [$encoded->view->width, $encoded->thumb->width]);
        }

        sort($seconds);
        $p95 = $seconds[(int) ceil(0.95 * self::RUNS) - 1] ?? \INF;
        fwrite(\STDERR, \sprintf(
            "\n%d runs of a %d byte PNG: p50 %.2f s, p95 %.2f s, max %.2f s\n",
            self::RUNS,
            \strlen($original),
            $seconds[intdiv(self::RUNS, 2) - 1] ?? \INF,
            $p95,
            $seconds[self::RUNS - 1] ?? \INF,
        ));
        self::assertLessThanOrEqual(self::P95_LIMIT_SECONDS, $p95);
    }

    private static function noisePng(): string
    {
        $rows = '';
        for ($row = 0; $row < self::HEIGHT; ++$row) {
            $rows .= "\x00".random_bytes(self::WIDTH * 3);
        }
        $storedBlocks = gzcompress($rows, 0);
        if (false === $storedBlocks) {
            throw new \RuntimeException('The picture rows could not be put into deflate blocks.');
        }

        return "\x89PNG\r\n\x1a\n"
            .self::pngChunk('IHDR', pack('NNCCCCC', self::WIDTH, self::HEIGHT, 8, 2, 0, 0, 0))
            .self::pngChunk('IDAT', $storedBlocks)
            .self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
