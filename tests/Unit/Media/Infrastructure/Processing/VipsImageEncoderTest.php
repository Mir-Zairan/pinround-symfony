<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Processing;

use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\UndecodableImage;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\Media\Infrastructure\Processing\VipsImageEncoder;
use App\Tests\Fixtures\Media\ImageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VipsImageEncoder with a stand-in for the vips tool: a PHP script that logs its arguments, keeps what the loader was
 * given and answers as the test's behaviour file says: a loader that decodes (writes its output file) or refuses (exit
 * 1, as vips does) or hangs, and a thumbnail that copies the test's file for its width: a WebP, one with metadata
 * chunks, a broken container, a PNG, junk or nothing. The real tool runs in
 * tests/Integration/Media/Processing/VipsImageEncoderTest.php.
 */
final class VipsImageEncoderTest extends TestCase
{
    private const string FAKE_VIPS = <<<'PHP'
        <?php
        $folder = __DIR__;
        $arguments = array_slice($argv, 1);
        file_put_contents($folder.'/calls.log', implode(' ', $arguments)."\n", FILE_APPEND);
        $behaviour = json_decode((string) file_get_contents($folder.'/behaviour.json'), true);
        if (str_ends_with($arguments[0], 'load')) {
            copy($arguments[1], $folder.'/seen-original');
            file_put_contents($folder.'/directory-mode', sprintf('%o', fileperms(dirname($arguments[1])) & 0o777));
            if ('hang' === $behaviour['load']) {
                sleep(10);
            }
            if ('refuse' === $behaviour['load']) {
                fwrite(STDERR, str_repeat("VIPS-WARNING **: not enough data\n", 1000));
                exit(1);
            }
            file_put_contents($arguments[2], 'decoded');
            exit(0);
        }
        $output = explode('[', $arguments[2])[0];
        $variant = $folder.'/thumbnail-'.$behaviour['thumbnail'].'-'.$arguments[3];
        if (is_file($variant)) {
            copy($variant, $output);
        }
        exit(0);
        PHP;

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = \sprintf('%s/vips-encoder-test-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->folder, 0o700);
        file_put_contents($this->folder.'/vips', '#!'.\PHP_BINARY."\n".self::FAKE_VIPS);
        chmod($this->folder.'/vips', 0o700);
        file_put_contents($this->folder.'/thumbnail-webp-1920', ImageFixtures::webpLossless(1920, 640));
        file_put_contents($this->folder.'/thumbnail-webp-480', ImageFixtures::webpLossy(480, 160));
        file_put_contents($this->folder.'/thumbnail-metadata-1920', ImageFixtures::webpWithMetadata(1920, 640));
        file_put_contents($this->folder.'/thumbnail-metadata-480', ImageFixtures::webpWithMetadata(480, 160));
        file_put_contents($this->folder.'/thumbnail-png-1920', ImageFixtures::png(1920, 640));
        file_put_contents($this->folder.'/thumbnail-junk-1920', 'not a picture');
        file_put_contents($this->folder.'/thumbnail-empty-1920', '');
        file_put_contents($this->folder.'/not-executable-vips', '#!/bin/sh'."\n");
        chmod($this->folder.'/not-executable-vips', 0o600);
        file_put_contents($this->folder.'/thumbnail-cut-chunk-1920', self::riff('VP8L'.pack('V', 100)."\x2F\x00\x00\x00\x00"));
        file_put_contents($this->folder.'/thumbnail-cut-chunk-header-1920', self::riff('VP8'));
        file_put_contents($this->folder.'/thumbnail-no-bitstream-1920', self::riff('EXIF'.pack('V', 4).'Exif'));
    }

    protected function tearDown(): void
    {
        $names = scandir($this->folder);
        foreach (false === $names ? [] : $names as $name) {
            if ('.' !== $name && '..' !== $name) {
                unlink($this->folder.'/'.$name);
            }
        }
        rmdir($this->folder);
    }

    #[Test]
    public function theOriginalIsDecodedAsItsFormatThenScaledDownByWidthIntoTwoStrippedWebpsWhoseSizesComeFromTheirHeaders(): void
    {
        $this->behave(load: 'decode', thumbnail: 'webp');
        $original = ImageFixtures::withAppended(ImageFixtures::png(3000, 1000));

        $encoded = $this->encoder()->encode($original, ImageFormat::Png);

        self::assertSame(
            [ImageFixtures::webpLossless(1920, 640), 1920, 640, ImageFixtures::webpLossy(480, 160), 480, 160],
            [$encoded->view->bytes, $encoded->view->width, $encoded->view->height, $encoded->thumb->bytes, $encoded->thumb->width, $encoded->thumb->height],
        );
        $calls = $this->calls();
        self::assertCount(3, $calls);
        $directory = \dirname($calls[0][1]);
        self::assertStringStartsWith(rtrim(sys_get_temp_dir(), '/').'/pinround-vips-', $directory);
        self::assertSame([
            ['pngload', $directory.'/original', $directory.'/decoded.v', '--fail-on', 'error'],
            ['thumbnail', $directory.'/decoded.v', $directory.'/view.webp[strip,Q=85]', '1920', '--height', '10000000', '--size', 'down', '--export-profile', 'srgb'],
            ['thumbnail', $directory.'/decoded.v', $directory.'/thumb.webp[strip,Q=75]', '480', '--height', '10000000', '--size', 'down', '--export-profile', 'srgb'],
        ], $calls);
        self::assertSame($original, file_get_contents($this->folder.'/seen-original'), 'vips was given the whole original, appended bytes included.');
        self::assertSame('700', file_get_contents($this->folder.'/directory-mode'));
        self::assertDirectoryDoesNotExist($directory, 'The original, the decoded file and both variants are deleted.');
    }

    /**
     * @return iterable<string, array{ImageFormat, string}>
     */
    public static function loaders(): iterable
    {
        yield 'png' => [ImageFormat::Png, 'pngload'];
        yield 'jpeg' => [ImageFormat::Jpeg, 'jpegload'];
        yield 'webp' => [ImageFormat::Webp, 'webpload'];
    }

    #[Test]
    #[DataProvider('loaders')]
    public function eachFormatIsDecodedByItsOwnLoaderOnly(ImageFormat $format, string $loader): void
    {
        $this->behave(load: 'decode', thumbnail: 'webp');

        $this->encoder()->encode('picture bytes', $format);

        self::assertSame($loader, $this->calls()[0][0]);
    }

    #[Test]
    public function anOriginalTheLoaderRefusesIsUndecodableNoThumbnailIsMadeAndItsFilesAreDeleted(): void
    {
        $this->behave(load: 'refuse', thumbnail: 'webp');

        try {
            $this->encoder()->encode(ImageFixtures::pngClaiming(800, 600), ImageFormat::Png);
            self::fail('A refused original was encoded.');
        } catch (UndecodableImage $refusal) {
            self::assertSame('vips pngload refused the image (exit code 1).', $refusal->getMessage());
        }

        $calls = $this->calls();
        self::assertCount(1, $calls, 'No thumbnail was asked for.');
        self::assertDirectoryDoesNotExist(\dirname($calls[0][1]));
    }

    #[Test]
    public function aLoaderThatRunsPastTheTimeoutIsStoppedAndTheOriginalIsUndecodable(): void
    {
        $this->behave(load: 'hang', thumbnail: 'webp');

        try {
            $this->encoder(timeoutSecondsPerRun: 0.5)->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('An original was encoded past the timeout.');
        } catch (UndecodableImage $refusal) {
            self::assertSame('vips pngload ran longer than 0.5 seconds.', $refusal->getMessage());
        }

        $calls = $this->calls();
        self::assertCount(1, $calls);
        self::assertDirectoryDoesNotExist(\dirname($calls[0][1]));
    }

    #[Test]
    public function metadataChunksVipsWritesDespiteStripAreRemovedAndTheirVp8xFlagsCleared(): void
    {
        $this->behave(load: 'decode', thumbnail: 'metadata');

        $encoded = $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);

        self::assertSame(ImageFixtures::webpExtended(1920, 640), $encoded->view->bytes);
        self::assertSame(ImageFixtures::webpExtended(480, 160), $encoded->thumb->bytes);
        self::assertSame([1920, 640, 480, 160], [$encoded->view->width, $encoded->view->height, $encoded->thumb->width, $encoded->thumb->height]);
    }

    #[Test]
    #[DataProvider('missingVariants')]
    public function aThumbnailThatWritesNoFileOrAnEmptyOneMakesTheOriginalUndecodable(string $thumbnail): void
    {
        $this->behave(load: 'decode', thumbnail: $thumbnail);

        try {
            $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('An original was encoded without its variant.');
        } catch (UndecodableImage $refusal) {
            self::assertSame('vips wrote no variant.', $refusal->getMessage());
        }

        self::assertDirectoryDoesNotExist(\dirname($this->calls()[0][1]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingVariants(): iterable
    {
        yield 'no file' => ['nothing'];
        yield 'an empty file' => ['empty'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function variantsThatAreNoWebp(): iterable
    {
        yield 'a png' => ['png', 'vips wrote a variant that is no WebP container.'];
        yield 'junk' => ['junk', 'vips wrote a variant that is no WebP container.'];
        yield 'a chunk longer than its container' => ['cut-chunk', 'vips wrote a WebP chunk longer than its container.'];
        yield 'a chunk header cut short' => ['cut-chunk-header', 'vips wrote a WebP with a broken chunk header.'];
        yield 'a container with no bitstream once its metadata is gone' => ['no-bitstream', 'vips wrote a variant that is no readable WebP.'];
    }

    #[Test]
    #[DataProvider('variantsThatAreNoWebp')]
    public function aVariantThatIsNoWebpIsAFailureOfTheToolNotARefusalOfThePicture(string $thumbnail, string $expectedFailure): void
    {
        $this->behave(load: 'decode', thumbnail: $thumbnail);

        try {
            $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('A variant that is no WebP was returned.');
        } catch (\RuntimeException $failure) {
            self::assertSame($expectedFailure, $failure->getMessage());
        }

        self::assertDirectoryDoesNotExist(\dirname($this->calls()[0][1]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toolsThatCannotRun(): iterable
    {
        yield 'not installed' => ['no-such-vips'];
        yield 'not executable' => ['not-executable-vips'];
    }

    #[Test]
    #[DataProvider('toolsThatCannotRun')]
    public function aVipsToolThatCannotRunIsAFailureToRetryNotARefusalOfThePicture(string $vipsBinary): void
    {
        $directoriesBefore = $this->encoderDirectories();

        try {
            new VipsImageEncoder(new PurePhpImageHeaderReader(), $this->folder.'/'.$vipsBinary)->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('An original was encoded without vips.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The vips tool is not installed or cannot be executed.', $failure->getMessage());
        }

        self::assertSame($directoriesBefore, $this->encoderDirectories(), 'The temporary directory is deleted.');
    }

    private function encoder(float $timeoutSecondsPerRun = VipsImageEncoder::TIMEOUT_SECONDS_PER_RUN): VipsImageEncoder
    {
        return new VipsImageEncoder(new PurePhpImageHeaderReader(), $this->folder.'/vips', $timeoutSecondsPerRun);
    }

    private static function riff(string $chunks): string
    {
        return 'RIFF'.pack('V', 4 + \strlen($chunks)).'WEBP'.$chunks;
    }

    private function behave(string $load, string $thumbnail): void
    {
        file_put_contents($this->folder.'/behaviour.json', json_encode(['load' => $load, 'thumbnail' => $thumbnail], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return non-empty-list<list<string>> the arguments of each call to the stand-in, in order
     */
    private function calls(): array
    {
        $log = file_get_contents($this->folder.'/calls.log');
        self::assertIsString($log, 'The stand-in for vips was called.');
        $calls = array_map(static fn (string $line): array => explode(' ', $line), explode("\n", rtrim($log, "\n")));

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function encoderDirectories(): array
    {
        $directories = glob(rtrim(sys_get_temp_dir(), '/').'/pinround-vips-*', \GLOB_ONLYDIR);

        return false === $directories ? [] : $directories;
    }
}
