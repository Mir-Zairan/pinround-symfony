<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Processing;

use App\Media\Application\Processing\EncodedImage;
use App\Media\Application\Processing\EncodedVariant;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\Processing\UndecodableImage;
use App\Media\Application\Processing\UnknownImageFormat;
use App\Media\Application\Processing\UnreadableImageHeader;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The ImageEncoder of worker-media: it runs the libvips command-line tool (the image's libvips-tools package) through
 * Process with an argument list, never a shell string (media-pipeline.md, Processing worker). The original is decoded
 * whole by its own format's loader with fail-on=error into a vips file, so a file cut short or broken is refused and
 * nothing after its image data is read. Each variant is a thumbnail of that file, scaled down to its maximum width
 * only, made sRGB and saved as WebP, of which only the picture's own chunks are kept. The files live in a directory only
 * this process's user can open (0700), which is deleted before encode() returns.
 */
final readonly class VipsImageEncoder implements ImageEncoder
{
    public const float TIMEOUT_SECONDS_PER_RUN = 30.0;

    /** WebP quality (0-100) per variant, as media-pipeline.md sets it. */
    private const int VIEW_QUALITY = 85;
    private const int THUMB_QUALITY = 75;
    /** The largest height vips thumbnail takes: the box it fits a variant into is then limited by the width alone. */
    private const int UNLIMITED_HEIGHT = 10_000_000;
    /** What the shell answers when the program cannot be executed (126) or is not found (127); vips itself exits 1. */
    private const array EXIT_CODES_OF_A_MISSING_TOOL = [126, 127];
    /** The chunks of a still WebP's pixels (RFC 9649, 2.7); any other chunk is metadata or animation. */
    private const array PICTURE_CHUNKS = ['VP8X', 'VP8 ', 'VP8L', 'ALPH'];
    /** The ICC profile (0x20), EXIF (0x08) and XMP (0x04) flags of a VP8X chunk's first byte. */
    private const int VP8X_METADATA_FLAGS = 0x2C;

    public function __construct(
        private ImageHeaderReader $imageHeaderReader,
        private string $vipsBinary = 'vips',
        private float $timeoutSecondsPerRun = self::TIMEOUT_SECONDS_PER_RUN,
    ) {
    }

    /**
     * @throws UndecodableImage  when vips refuses the original, runs past the timeout or writes an empty variant
     * @throws \RuntimeException when vips cannot be started or the temporary files cannot be written, so the caller
     *                           retries; or when vips writes a variant that is no WebP
     */
    public function encode(string $original, ImageFormat $format): EncodedImage
    {
        $directory = self::createPrivateDirectory();
        try {
            $originalPath = $directory.'/original';
            if (\strlen($original) !== @file_put_contents($originalPath, $original)) {
                throw new \RuntimeException('The original could not be written for the image encoder.');
            }
            $decodedPath = $directory.'/decoded.v';
            $this->runVips([self::loaderOf($format), $originalPath, $decodedPath, '--fail-on', 'error']);

            return new EncodedImage(
                $this->thumbnail($decodedPath, $directory.'/view.webp', self::VIEW_MAX_WIDTH, self::VIEW_QUALITY),
                $this->thumbnail($decodedPath, $directory.'/thumb.webp', self::THUMB_MAX_WIDTH, self::THUMB_QUALITY),
            );
        } finally {
            self::deleteDirectory($directory);
        }
    }

    private function thumbnail(string $decodedPath, string $variantPath, int $maxWidth, int $quality): EncodedVariant
    {
        $this->runVips([
            'thumbnail', $decodedPath, \sprintf('%s[strip,Q=%d]', $variantPath, $quality), (string) $maxWidth,
            '--height', (string) self::UNLIMITED_HEIGHT, '--size', 'down', '--export-profile', 'srgb',
        ]);

        $written = is_file($variantPath) ? file_get_contents($variantPath) : false;
        if (false === $written || '' === $written) {
            throw new UndecodableImage('vips wrote no variant.');
        }
        $bytes = self::withPictureChunksOnly($written);
        try {
            $header = $this->imageHeaderReader->read(substr($bytes, 0, ImageHeaderReader::LEADING_BYTES));
        } catch (UnknownImageFormat|UnreadableImageHeader $unreadable) {
            throw new \RuntimeException('vips wrote a variant that is no readable WebP.', 0, $unreadable);
        }

        return new EncodedVariant($bytes, $header->width, $header->height);
    }

    /**
     * @param non-empty-list<string> $arguments the vips operation first
     */
    private function runVips(array $arguments): void
    {
        // vips warns once per broken tile, megabytes for a large broken file; only the exit code is used.
        $process = new Process([$this->vipsBinary, ...$arguments], timeout: $this->timeoutSecondsPerRun);
        $process->disableOutput();
        try {
            $exitCode = $process->run();
        } catch (ProcessStartFailedException $notStarted) {
            throw new \RuntimeException('The vips tool could not be started.', 0, $notStarted);
        } catch (ProcessTimedOutException) {
            throw new UndecodableImage(\sprintf('vips %s ran longer than %s seconds.', $arguments[0], $this->timeoutSecondsPerRun));
        }
        if (\in_array($exitCode, self::EXIT_CODES_OF_A_MISSING_TOOL, true)) {
            throw new \RuntimeException('The vips tool is not installed or cannot be executed.');
        }
        if (0 !== $exitCode) {
            throw new UndecodableImage(\sprintf('vips %s refused the image (exit code %d).', $arguments[0], $exitCode));
        }
    }

    /**
     * libvips 8.14 (Debian bookworm) writes an EXIF chunk into a WebP even when told to strip, so the variant is
     * rebuilt here from its picture chunks alone, with VP8X's metadata flags cleared.
     *
     * @throws \RuntimeException when $webp is no RIFF WebP container whose chunks end where it ends
     */
    private static function withPictureChunksOnly(string $webp): string
    {
        $riffSize = \strlen($webp) >= 12 ? unpack('V', $webp, 4) : false;
        if (false === $riffSize || !\is_int($riffSize[1] ?? null) || !str_starts_with($webp, 'RIFF') || 'WEBP' !== substr($webp, 8, 4) || 8 + $riffSize[1] !== \strlen($webp)) {
            throw new \RuntimeException('vips wrote a variant that is no WebP container.');
        }

        $pictureChunks = '';
        $offset = 12;
        while ($offset < \strlen($webp)) {
            $length = $offset + 8 <= \strlen($webp) ? unpack('V', $webp, $offset + 4) : false;
            if (false === $length || !\is_int($length[1] ?? null)) {
                throw new \RuntimeException('vips wrote a WebP with a broken chunk header.');
            }
            $type = substr($webp, $offset, 4);
            $paddedLength = $length[1] + ($length[1] & 1);
            if ($offset + 8 + $paddedLength > \strlen($webp)) {
                throw new \RuntimeException('vips wrote a WebP chunk longer than its container.');
            }
            if (\in_array($type, self::PICTURE_CHUNKS, true)) {
                $payload = substr($webp, $offset + 8, $paddedLength);
                if ('VP8X' === $type && '' !== $payload) {
                    $payload[0] = \chr(\ord($payload[0]) & ~self::VP8X_METADATA_FLAGS);
                }
                $pictureChunks .= $type.pack('V', $length[1]).$payload;
            }
            $offset += 8 + $paddedLength;
        }

        return 'RIFF'.pack('V', 4 + \strlen($pictureChunks)).'WEBP'.$pictureChunks;
    }

    private static function loaderOf(ImageFormat $format): string
    {
        return match ($format) {
            ImageFormat::Png => 'pngload',
            ImageFormat::Jpeg => 'jpegload',
            ImageFormat::Webp => 'webpload',
        };
    }

    /**
     * A new directory under a random name: mkdir fails on a name that exists, so no one can plant a file or a link
     * there first, and 0700 (never widened by the umask) keeps the original and its variants from other users.
     */
    private static function createPrivateDirectory(): string
    {
        $directory = \sprintf('%s/pinround-vips-%s', rtrim(sys_get_temp_dir(), '/'), bin2hex(random_bytes(16)));
        if (!@mkdir($directory, 0o700)) {
            throw new \RuntimeException('No temporary directory could be created for the image encoder.');
        }

        return $directory;
    }

    private static function deleteDirectory(string $directory): void
    {
        $names = scandir($directory);
        foreach (false === $names ? [] : $names as $name) {
            if ('.' !== $name && '..' !== $name) {
                @unlink($directory.'/'.$name);
            }
        }
        @rmdir($directory);
    }
}
