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
 * given and the environment it got, and answers as the test's behaviour file says: a loader that decodes (writes its
 * output file), refuses (exit 1, as vips does), hangs or kills itself with a signal, and a thumbnail that copies the
 * test's file for its width: a WebP, one with metadata chunks, a broken container, a PNG, junk or nothing. The real tool
 * runs in
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
            file_put_contents($folder.'/environment.json', json_encode(getenv()));
            if ('hang' === $behaviour['load']) {
                sleep(10);
            }
            if (str_starts_with($behaviour['load'], 'exit-')) {
                exit((int) substr($behaviour['load'], 5));
            }
            if (str_starts_with($behaviour['load'], 'signal-')) {
                posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);
                posix_kill(getmypid(), (int) substr($behaviour['load'], 7));
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
        file_put_contents($this->folder.'/thumbnail-bytes-after-container-1920', ImageFixtures::webpLossless(1920, 640).'tail');
        $webp = ImageFixtures::webpLossless(1920, 640);
        file_put_contents($this->folder.'/thumbnail-size-past-the-end-1920', 'RIFF'.pack('V', \strlen($webp) - 8 + 100).substr($webp, 8));
        $wave = 'fmt '.pack('V', 16).str_repeat("\x00", 16);
        file_put_contents($this->folder.'/thumbnail-wave-1920', 'RIFF'.pack('V', 4 + \strlen($wave)).'WAVE'.$wave);
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
    public function theOriginalIsDecodedAsItsFormatThenScaledDownByWidthAndWebpsLargestHeightIntoTwoStrippedWebpsWhoseSizesComeFromTheirHeaders(): void
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
            ['thumbnail', $directory.'/decoded.v', $directory.'/view.webp[strip,Q=85]', '1920', '--height', '16383', '--size', 'down', '--export-profile', 'srgb'],
            ['thumbnail', $directory.'/decoded.v', $directory.'/thumb.webp[strip,Q=75]', '480', '--height', '16383', '--size', 'down', '--export-profile', 'srgb'],
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'exit code 1, as vips refuses' => ['refuse', 'vips pngload refused the image (exit code 1).'];
        yield 'exit code 2' => ['exit-2', 'vips pngload refused the image (exit code 2).'];
        yield 'exit code 128, next to the codes of a missing tool' => ['exit-128', 'vips pngload refused the image (exit code 128).'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function anOriginalTheLoaderRefusesIsUndecodableNoThumbnailIsMadeAndItsFilesAreDeleted(string $load, string $expectedRefusal): void
    {
        $this->behave(load: $load, thumbnail: 'webp');

        try {
            $this->encoder()->encode(ImageFixtures::pngClaiming(800, 600), ImageFormat::Png);
            self::fail('A refused original was encoded.');
        } catch (UndecodableImage $refusal) {
            self::assertSame($expectedRefusal, $refusal->getMessage());
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
    public function metadataChunksVipsWritesDespiteStripAreRemovedAndTheirVp8xFlagsClearedWhileTheAlphaFlagStays(): void
    {
        $this->behave(load: 'decode', thumbnail: 'metadata');

        $encoded = $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);

        self::assertSame(ImageFixtures::webpExtended(1920, 640, alphaFlag: true), $encoded->view->bytes);
        self::assertSame(ImageFixtures::webpExtended(480, 160, alphaFlag: true), $encoded->thumb->bytes);
        // The VP8X flag byte follows 'RIFF', the RIFF size, 'WEBP', 'VP8X' and the chunk size.
        self::assertSame([0x10, 0x10], [\ord($encoded->view->bytes[20]), \ord($encoded->thumb->bytes[20])]);
        self::assertSame([1920, 640, 480, 160], [$encoded->view->width, $encoded->view->height, $encoded->thumb->width, $encoded->thumb->height]);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function crashSignals(): iterable
    {
        yield 'SIGILL' => [4, 'vips pngload crashed on the image (signal 4).'];
        yield 'SIGABRT' => [6, 'vips pngload crashed on the image (signal 6).'];
        yield 'SIGBUS' => [7, 'vips pngload crashed on the image (signal 7).'];
        yield 'SIGFPE' => [8, 'vips pngload crashed on the image (signal 8).'];
        yield 'SIGSEGV' => [11, 'vips pngload crashed on the image (signal 11).'];
    }

    #[Test]
    #[DataProvider('crashSignals')]
    public function aDecoderThatCrashesOnTheOriginalMakesItUndecodable(int $signal, string $expectedRefusal): void
    {
        $this->behave(load: 'signal-'.$signal, thumbnail: 'webp');

        try {
            $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('An original was encoded by a crashed decoder.');
        } catch (UndecodableImage $refusal) {
            self::assertSame($expectedRefusal, $refusal->getMessage());
        }

        $calls = $this->calls();
        self::assertCount(1, $calls, 'No thumbnail was asked for.');
        self::assertDirectoryDoesNotExist(\dirname($calls[0][1]));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function signalsFromOutside(): iterable
    {
        yield 'SIGKILL, as the OOM killer sends' => [9, 'vips pngload was stopped by signal 9.'];
        yield 'SIGTERM' => [15, 'vips pngload was stopped by signal 15.'];
    }

    #[Test]
    #[DataProvider('signalsFromOutside')]
    public function aDecoderStoppedByASignalFromOutsideIsAFailureToRetryNotARefusalOfThePicture(int $signal, string $expectedFailure): void
    {
        $this->behave(load: 'signal-'.$signal, thumbnail: 'webp');

        try {
            $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
            self::fail('An original was encoded by a stopped decoder.');
        } catch (\RuntimeException $failure) {
            self::assertSame($expectedFailure, $failure->getMessage());
        }

        $calls = $this->calls();
        self::assertCount(1, $calls);
        self::assertDirectoryDoesNotExist(\dirname($calls[0][1]));
    }

    #[Test]
    public function vipsInheritsOnlyPathHomeTmpdirAndLangAndNoSecretOfTheWorker(): void
    {
        $this->behave(load: 'decode', thumbnail: 'webp');
        // A variable the worker was started with is in getenv() and $_SERVER; one Dotenv loaded is in $_ENV.
        putenv('PINROUND_TEST_STORAGE_SECRET=secret-from-the-process-environment');
        $_SERVER['PINROUND_TEST_STORAGE_SECRET'] = 'secret-from-the-process-environment';
        $_ENV['PINROUND_TEST_DATABASE_URL'] = 'postgresql://app:secret-from-dotenv@db/app';
        try {
            $this->encoder()->encode(ImageFixtures::png(10, 10), ImageFormat::Png);
        } finally {
            putenv('PINROUND_TEST_STORAGE_SECRET');
            unset($_SERVER['PINROUND_TEST_STORAGE_SECRET'], $_ENV['PINROUND_TEST_DATABASE_URL']);
        }

        $environment = json_decode((string) file_get_contents($this->folder.'/environment.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($environment);
        $expected = array_filter(['PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), 'TMPDIR' => getenv('TMPDIR'), 'LANG' => getenv('LANG')], static fn (string|false $value): bool => false !== $value);
        self::assertArrayHasKey('PATH', $expected, 'The test runs with a PATH, so the rule that passes it on is checked.');
        self::assertArrayNotHasKey('PINROUND_TEST_STORAGE_SECRET', $environment);
        self::assertArrayNotHasKey('PINROUND_TEST_DATABASE_URL', $environment);
        ksort($expected);
        ksort($environment);
        self::assertSame($expected, $environment);
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
        yield 'a webp with bytes after its container' => ['bytes-after-container', 'vips wrote a variant that is no WebP container.'];
        yield 'a riff size past the end of the file' => ['size-past-the-end', 'vips wrote a variant that is no WebP container.'];
        yield 'a riff wave container' => ['wave', 'vips wrote a variant that is no WebP container.'];
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
