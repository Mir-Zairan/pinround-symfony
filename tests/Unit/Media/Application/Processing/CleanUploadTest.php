<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application\Processing;

use App\Media\Api\Event\MediaReady;
use App\Media\Api\Event\MediaRejected;
use App\Media\Application\Processing\CleanUpload;
use App\Media\Application\Processing\EncodedImage;
use App\Media\Application\Processing\EncodedVariant;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\ProcessUpload;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\UploadPolicy;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\Media\Infrastructure\Processing\UnavailableImageEncoder;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Fixtures\Media\FakeImageEncoder;
use App\Tests\Fixtures\Media\ImageFixtures;
use App\Tests\Fixtures\Media\PassThroughImageEncoder;
use App\Tests\Unit\Media\Application\InMemoryMediaObjects;
use App\Tests\Unit\Media\Application\InMemoryTransactions;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CleanUpload over in-memory ports that behave like the Doctrine, Flysystem, Messenger and ledger ones, with the real
 * PurePhpImageHeaderReader and a FakeImageEncoder; some cases use the UnavailableImageEncoder that dev and prod run
 * today, or a PassThroughImageEncoder that breaks the contract as a real encoder could. The clock stands at 2026-03-02
 * 10:00 UTC. Every rejection is checked for its reason, its event, the deleted upload and that nothing else was stored.
 */
final class CleanUploadTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1801';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1802';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1803';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000e1811';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000e1812';
    private const string UNKNOWN_MEDIA = '0197a3c0-0000-7000-8000-0000000e1819';
    private const string NOW = '2026-03-02 10:00:00 UTC';
    private const string UPLOADED_AT = '2026-03-02 09:58:00 UTC';

    private TenantContext $tenantContext;
    private InMemoryTransactions $transactions;
    private InMemoryOnceOnlyHandling $onceOnlyHandling;
    private InMemoryMediaObjects $mediaObjects;
    private InMemoryMediaFiles $mediaFiles;
    private RecordingMediaEvents $mediaEvents;
    private FakeImageEncoder $imageEncoder;
    private CleanUpload $cleanUpload;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->onceOnlyHandling = new InMemoryOnceOnlyHandling($this->transactions);
        $this->mediaObjects = new InMemoryMediaObjects($this->transactions, $this->tenantContext);
        $this->mediaFiles = new InMemoryMediaFiles();
        $this->mediaEvents = new RecordingMediaEvents($this->transactions);
        $this->imageEncoder = new FakeImageEncoder();
        $this->cleanUpload = $this->cleanUploadWith($this->imageEncoder);
    }

    #[Test]
    public function aValidPngIsStoredAsAViewAndAThumbScaledDownAndTheUploadIsDeletedAndTheObjectIsReady(): void
    {
        $original = ImageFixtures::png(2400, 1200);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');

        $this->clean(self::FIRST_MEDIA);

        $viewKey = $this->variantKey(self::FIRST_MEDIA, 'view');
        $thumbKey = $this->variantKey(self::FIRST_MEDIA, 'thumb');
        self::assertSame([$thumbKey, $viewKey], $this->mediaFiles->keys(), 'Only the two variants are left: the upload is deleted.');
        self::assertSame(['image/webp', 'image/webp'], [$this->mediaFiles->mimeTypeOf($viewKey), $this->mediaFiles->mimeTypeOf($thumbKey)]);
        self::assertSame([ImageFormat::Webp, 1920, 960], $this->webpHeaderOf($viewKey));
        self::assertSame([ImageFormat::Webp, 480, 240], $this->webpHeaderOf($thumbKey));
        self::assertSame(
            [MediaStatus::Ready, null, hash('sha256', $original), 1920, 960, ['view' => $viewKey, 'thumb' => $thumbKey]],
            $this->stateOf(self::FIRST_MEDIA),
        );
        self::assertEquals([new MediaReady(self::ACME_FOODS, self::FIRST_MEDIA, new \DateTimeImmutable(self::NOW))], $this->mediaEvents->published());
        self::assertSame(1, $this->imageEncoder->encodeCount);
    }

    #[Test]
    public function aPictureSmallerThanTheMaximumsIsNeverScaledUp(): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::png(100, 50), 'image/png');

        $this->clean(self::FIRST_MEDIA);

        self::assertSame([ImageFormat::Webp, 100, 50], $this->webpHeaderOf($this->variantKey(self::FIRST_MEDIA, 'view')));
        self::assertSame([ImageFormat::Webp, 100, 50], $this->webpHeaderOf($this->variantKey(self::FIRST_MEDIA, 'thumb')));
        self::assertSame(100, $this->stateOf(self::FIRST_MEDIA)[3]);
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function validPictures(): iterable
    {
        yield 'jpeg' => [ImageFixtures::jpeg(3000, 1500), 'image/jpeg', 1920, 960];
        yield 'lossy webp' => [ImageFixtures::webpLossy(1000, 500), 'image/webp', 1000, 500];
        yield 'lossless webp' => [ImageFixtures::webpLossless(2000, 1000), 'image/webp', 1920, 960];
        yield 'extended webp' => [ImageFixtures::webpExtended(640, 480), 'image/webp', 640, 480];
    }

    #[Test]
    #[DataProvider('validPictures')]
    public function everyAcceptedTypeIsReEncodedToAReadyObjectOfTheScaledSize(string $original, string $mime, int $viewWidth, int $viewHeight): void
    {
        $this->upload(self::FIRST_MEDIA, $original, $mime);

        $this->clean(self::FIRST_MEDIA);

        $state = $this->stateOf(self::FIRST_MEDIA);
        self::assertSame([MediaStatus::Ready, $viewWidth, $viewHeight], [$state[0], $state[3], $state[4]]);
        self::assertSame([ImageFormat::Webp, $viewWidth, $viewHeight], $this->webpHeaderOf($this->variantKey(self::FIRST_MEDIA, 'view')));
    }

    #[Test]
    public function aPngWithAppendedScriptBytesLeavesNoByteOfThemInAnyStoredVariant(): void
    {
        $polyglot = ImageFixtures::withAppended(ImageFixtures::png(64, 64));
        self::assertStringContainsString(ImageFixtures::SCRIPT_BYTES, $polyglot);
        $this->upload(self::FIRST_MEDIA, $polyglot, 'image/png');

        $this->clean(self::FIRST_MEDIA);

        self::assertSame(MediaStatus::Ready, $this->stateOf(self::FIRST_MEDIA)[0]);
        $keys = $this->mediaFiles->keys();
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            $variant = $this->mediaFiles->bytesOf($key);
            self::assertStringNotContainsString(ImageFixtures::SCRIPT_BYTES, $variant);
            self::assertStringNotContainsString('<?php', $variant);
            self::assertStringNotContainsString("\x89PNG", $variant);
            self::assertStringStartsWith('RIFF', $variant);
        }
        self::assertSame(hash('sha256', $polyglot), $this->stateOf(self::FIRST_MEDIA)[2], 'The hash is of the original as uploaded.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function polyglotsOfTheOtherTypes(): iterable
    {
        yield 'a jpeg' => [ImageFixtures::withAppended(ImageFixtures::jpeg(64, 64)), 'image/jpeg'];
        yield 'a lossless webp' => [ImageFixtures::withAppended(ImageFixtures::webpLossless(64, 64)), 'image/webp'];
        yield 'a lossy webp' => [ImageFixtures::withAppended(ImageFixtures::webpLossy(64, 64)), 'image/webp'];
    }

    #[Test]
    #[DataProvider('polyglotsOfTheOtherTypes')]
    public function aJpegOrWebpWithAppendedScriptBytesLeavesNoByteOfThemInAnyStoredVariant(string $polyglot, string $mime): void
    {
        $this->upload(self::FIRST_MEDIA, $polyglot, $mime);

        $this->clean(self::FIRST_MEDIA);

        self::assertSame(MediaStatus::Ready, $this->stateOf(self::FIRST_MEDIA)[0]);
        $keys = $this->mediaFiles->keys();
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            $variant = $this->mediaFiles->bytesOf($key);
            self::assertStringNotContainsString(ImageFixtures::SCRIPT_BYTES, $variant);
            self::assertStringNotContainsString('<?php', $variant);
            self::assertSame(self::riffContainerLengthOf($variant), \strlen($variant), 'The variant does not end where its RIFF container ends.');
        }
    }

    #[Test]
    public function aWebpPolyglotThatTheEncoderPassesThroughIsRefusedForItsTailAndNothingIsStored(): void
    {
        $polyglot = ImageFixtures::withAppended(ImageFixtures::webpLossless(100, 50), ImageFixtures::SCRIPT_BYTES);
        $this->upload(self::FIRST_MEDIA, $polyglot, 'image/webp');
        $passThrough = new PassThroughImageEncoder();

        try {
            $this->cleanWith($passThrough, self::FIRST_MEDIA);
            self::fail('A variant carrying the script after its WebP container was stored.');
        } catch (\LogicException $refusal) {
            self::assertSame('The image encoder returned a variant whose length is not that of its WebP container.', $refusal->getMessage());
        }

        self::assertSame(1, $passThrough->encodeCount);
        $this->assertNothingChangedFor(self::FIRST_MEDIA, $polyglot);
    }

    #[Test]
    public function aCleanWebpThatTheEncoderPassesThroughIsRefusedAsTheOriginalAndNothingIsStored(): void
    {
        $original = ImageFixtures::webpLossless(100, 50);
        $this->upload(self::FIRST_MEDIA, $original, 'image/webp');
        $passThrough = new PassThroughImageEncoder();

        try {
            $this->cleanWith($passThrough, self::FIRST_MEDIA);
            self::fail('A variant that is the original was stored.');
        } catch (\LogicException $refusal) {
            self::assertSame('The image encoder returned the original as a variant.', $refusal->getMessage());
        }

        self::assertSame(1, $passThrough->encodeCount);
        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    #[Test]
    public function aPngPolyglotThatTheEncoderPassesThroughIsRefusedAsNoWebpAndNothingIsStored(): void
    {
        $polyglot = ImageFixtures::withAppended(ImageFixtures::png(64, 64));
        $this->upload(self::FIRST_MEDIA, $polyglot, 'image/png');

        try {
            $this->cleanWith(new PassThroughImageEncoder(), self::FIRST_MEDIA);
            self::fail('A variant that is the original was stored.');
        } catch (\LogicException $refusal) {
            self::assertSame('The image encoder returned a variant that is not a still WebP of its stated size, at most 1920 px wide.', $refusal->getMessage());
        }

        $this->assertNothingChangedFor(self::FIRST_MEDIA, $polyglot);
    }

    #[Test]
    public function withTheUnavailableEncoderACheckedUploadStaysPendingWithItsUploadAndPublishesNothing(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');

        try {
            $this->cleanWith(new UnavailableImageEncoder(), self::FIRST_MEDIA);
            self::fail('An upload was finished without an encoder.');
        } catch (\RuntimeException $failure) {
            self::assertSame('No image encoder is installed, so uploads cannot be re-encoded yet.', $failure->getMessage());
        }

        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    #[Test]
    public function withTheUnavailableEncoderADuplicateOfAReadyPictureStillBecomesReadyWithItsVariants(): void
    {
        $original = ImageFixtures::png(300, 200);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $firstState = $this->stateOf(self::FIRST_MEDIA);
        self::assertSame(MediaStatus::Ready, $firstState[0]);
        $storedAfterFirst = $this->mediaFiles->keys();
        $this->upload(self::SECOND_MEDIA, $original, 'image/png');

        $this->cleanWith(new UnavailableImageEncoder(), self::SECOND_MEDIA, 'message-2');

        self::assertSame($firstState, $this->stateOf(self::SECOND_MEDIA));
        self::assertSame($storedAfterFirst, $this->mediaFiles->keys(), 'No variant was written, and the second upload object is deleted.');
        self::assertEquals(
            [new MediaReady(self::ACME_FOODS, self::FIRST_MEDIA, new \DateTimeImmutable(self::NOW)), new MediaReady(self::ACME_FOODS, self::SECOND_MEDIA, new \DateTimeImmutable(self::NOW))],
            $this->mediaEvents->published(),
        );
    }

    /**
     * @return iterable<string, array{\Closure(): EncodedImage, string}>
     */
    public static function encodersBreakingTheContract(): iterable
    {
        $good = static fn (): EncodedVariant => new EncodedVariant(ImageFixtures::webpLossless(100, 50), 100, 50);
        $notReadable = 'The image encoder returned a variant that is no readable WebP.';
        $notStill = 'The image encoder returned a variant that is not a still WebP of its stated size, at most %d px wide.';
        $notItsContainer = 'The image encoder returned a variant whose length is not that of its WebP container.';

        yield 'a view that is junk' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant('junk', 100, 50), $good()), $notReadable];
        yield 'a thumb that is a png' => [static fn (): EncodedImage => new EncodedImage($good(), new EncodedVariant(ImageFixtures::png(10, 10), 10, 10)), 'The image encoder returned a variant that is not a still WebP of its stated size, at most 480 px wide.'];
        yield 'a view whose stated width is not the header width' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant(ImageFixtures::webpLossless(100, 50), 101, 50), $good()), \sprintf($notStill, 1920)];
        yield 'a view whose stated height is not the header height' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant(ImageFixtures::webpLossless(100, 50), 100, 49), $good()), \sprintf($notStill, 1920)];
        yield 'a view wider than 1920' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant(ImageFixtures::webpLossless(1921, 10), 1921, 10), $good()), \sprintf($notStill, 1920)];
        yield 'a thumb wider than 480' => [static fn (): EncodedImage => new EncodedImage($good(), new EncodedVariant(ImageFixtures::webpLossless(481, 10), 481, 10)), \sprintf($notStill, 480)];
        yield 'an animated view' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant(ImageFixtures::webpExtended(100, 50, animationFlag: true), 100, 50), $good()), \sprintf($notStill, 1920)];
        yield 'a view with script bytes after its container' => [static fn (): EncodedImage => new EncodedImage(new EncodedVariant(ImageFixtures::withAppended(ImageFixtures::webpLossless(100, 50)), 100, 50), $good()), $notItsContainer];
        yield 'a thumb with one byte after its container' => [static fn (): EncodedImage => new EncodedImage($good(), new EncodedVariant(ImageFixtures::webpLossless(100, 50)."\x00", 100, 50)), $notItsContainer];
        yield 'a thumb cut short of its container' => [static fn (): EncodedImage => new EncodedImage($good(), new EncodedVariant(substr(ImageFixtures::webpLossless(100, 50), 0, -1), 100, 50)), $notItsContainer];
    }

    /**
     * @param \Closure(): EncodedImage $encoded
     */
    #[Test]
    #[DataProvider('encodersBreakingTheContract')]
    public function anEncoderThatBreaksItsContractStoresNothingAndLeavesTheObjectPendingForTheRetry(\Closure $encoded, string $expectedRefusal): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $breaking = $this->encoderReturning(static fn (): EncodedImage => $encoded());

        try {
            $this->cleanWith($breaking, self::FIRST_MEDIA);
            self::fail('An encoder that broke its contract was trusted.');
        } catch (\LogicException $refusal) {
            self::assertSame($expectedRefusal, $refusal->getMessage());
        }

        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    /**
     * @return iterable<string, array{int, int, MediaRejectReason}>
     */
    public static function objectsOfTheWrongSize(): iterable
    {
        $png = ImageFixtures::png(8, 8);
        $size = \strlen($png);

        yield 'larger than the intent' => [$size - 10, $size, MediaRejectReason::WrongSize];
        yield 'smaller than the intent' => [$size + 10, $size, MediaRejectReason::WrongSize];
        yield 'one byte larger than the intent' => [$size - 1, $size, MediaRejectReason::WrongSize];
        yield 'above 15 MB while the intent says 15 MB' => [UploadPolicy::MAX_BYTES, UploadPolicy::MAX_BYTES + 1, MediaRejectReason::TooLarge];
        yield 'above 15 MB as the intent says too' => [UploadPolicy::MAX_BYTES + 1, UploadPolicy::MAX_BYTES + 1, MediaRejectReason::TooLarge];
    }

    #[Test]
    #[DataProvider('objectsOfTheWrongSize')]
    public function anObjectNotTheSizeOfItsIntentOrAbove15MbIsDeletedAndRejectedWithoutReadingIt(int $declaredBytes, int $actualBytes, MediaRejectReason $expectedReason): void
    {
        $png = ImageFixtures::png(8, 8);
        $object = $png.str_repeat('x', max(0, $actualBytes - \strlen($png)));
        $this->upload(self::FIRST_MEDIA, substr($object, 0, $actualBytes), 'image/png', $declaredBytes);

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, $expectedReason);
        self::assertSame([], $this->mediaFiles->reads(), 'The size comes from a HEAD; no byte was read.');
    }

    #[Test]
    public function anEmptyObjectIsRejectedAsNotTheAnnouncedSize(): void
    {
        $this->upload(self::FIRST_MEDIA, '', 'image/png', 500);

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::WrongSize);
    }

    #[Test]
    public function anObjectOfExactly15MbThatIsAGoodPictureIsAccepted(): void
    {
        $png = ImageFixtures::png(8, 8);
        $original = ImageFixtures::withAppended($png, str_repeat('x', UploadPolicy::MAX_BYTES - \strlen($png)));
        self::assertSame(UploadPolicy::MAX_BYTES, \strlen($original));
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');

        $this->clean(self::FIRST_MEDIA);

        self::assertSame(MediaStatus::Ready, $this->stateOf(self::FIRST_MEDIA)[0]);
    }

    #[Test]
    public function aMissingObjectIsRejectedAsNotArrivedAndNothingIsEncoded(): void
    {
        $this->addPendingObject(self::FIRST_MEDIA, 'image/png', 500);

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::Missing);
    }

    /**
     * @return iterable<string, array{string, string, MediaRejectReason}>
     */
    public static function filesThatAreNotWhatTheyWereAnnouncedAs(): iterable
    {
        yield 'a gif announced as a png' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'image/png', MediaRejectReason::NotAnImage];
        yield 'a script announced as a jpeg' => [ImageFixtures::SCRIPT_BYTES, 'image/jpeg', MediaRejectReason::NotAnImage];
        yield 'text with a byte order mark and CRLF announced as a webp' => ["\xEF\xBB\xBFhello\r\nwörld", 'image/webp', MediaRejectReason::NotAnImage];
        yield 'a png announced as a jpeg' => [ImageFixtures::png(8, 8), 'image/jpeg', MediaRejectReason::WrongType];
        yield 'a jpeg announced as a png' => [ImageFixtures::jpeg(8, 8), 'image/png', MediaRejectReason::WrongType];
        yield 'a webp announced as a png' => [ImageFixtures::webpLossless(8, 8), 'image/png', MediaRejectReason::WrongType];
        yield 'a png announced as a webp' => [ImageFixtures::png(8, 8), 'image/webp', MediaRejectReason::WrongType];
        yield 'a png cut inside its header' => [substr(ImageFixtures::png(8, 8), 0, 20), 'image/png', MediaRejectReason::Unreadable];
        yield 'a jpeg with no frame header' => ["\xFF\xD8\xFF\xD9", 'image/jpeg', MediaRejectReason::Unreadable];
    }

    #[Test]
    #[DataProvider('filesThatAreNotWhatTheyWereAnnouncedAs')]
    public function aFileWhoseSignatureHeaderOrTypeIsWrongIsRejectedBeforeAnyDecoding(string $original, string $announcedMime, MediaRejectReason $expectedReason): void
    {
        $this->upload(self::FIRST_MEDIA, $original, $announcedMime);

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, $expectedReason);
    }

    /**
     * @return iterable<string, array{string, MediaRejectReason}>
     */
    public static function picturesAboveThePolicy(): iterable
    {
        yield '50,000 x 50,000 px' => [ImageFixtures::pngClaiming(50_000, 50_000), MediaRejectReason::TooManyPixels];
        yield '40,000,005 px, the fewest above 40 megapixels with both sides allowed' => [ImageFixtures::pngClaiming(3_015, 13_267), MediaRejectReason::TooManyPixels];
        yield '40,009,728 px with a width of exactly 16,384' => [ImageFixtures::pngClaiming(16_384, 2_442), MediaRejectReason::TooManyPixels];
        yield '7,000 x 7,000 px' => [ImageFixtures::pngClaiming(7_000, 7_000), MediaRejectReason::TooManyPixels];
        yield 'a side of 16,385 px' => [ImageFixtures::pngClaiming(16_385, 1), MediaRejectReason::TooManyPixels];
        yield 'a height of 16,385 px' => [ImageFixtures::pngClaiming(1, 16_385), MediaRejectReason::TooManyPixels];
        yield 'a jpeg of 65,535 x 65,535 px' => [ImageFixtures::jpeg(65_535, 65_535), MediaRejectReason::TooManyPixels];
        yield 'a webp of 16.7 million px a side' => [ImageFixtures::webpExtended(16_777_216, 16_777_216), MediaRejectReason::TooManyPixels];
        yield 'an animated png' => [ImageFixtures::animatedPng(32, 32), MediaRejectReason::Animated];
        yield 'an animated webp' => [ImageFixtures::webpExtended(32, 32, animationFlag: true), MediaRejectReason::Animated];
        yield 'an animated webp without the flag' => [ImageFixtures::webpExtended(32, 32, animationChunks: true), MediaRejectReason::Animated];
        yield 'an animated png that is also too big' => [ImageFixtures::animatedPng(20_000, 20_000), MediaRejectReason::Animated];
    }

    #[Test]
    #[DataProvider('picturesAboveThePolicy')]
    public function aTooBigOrAnimatedPictureIsRejectedFromItsHeaderAndTheEncoderIsNeverCalled(string $original, MediaRejectReason $expectedReason): void
    {
        $mime = str_starts_with($original, "\xFF") ? 'image/jpeg' : (str_starts_with($original, 'RIFF') ? 'image/webp' : 'image/png');
        $this->upload(self::FIRST_MEDIA, $original, $mime);

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, $expectedReason);
        self::assertSame(1, \count($this->mediaFiles->reads()), 'Only the leading bytes were read.');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function picturesAtThePolicyLimits(): iterable
    {
        yield 'exactly 40,000,000 px' => [8_000, 5_000];
        yield 'a width of exactly 16,384 px, at 39,993,344 px' => [16_384, 2_441];
        yield 'a height of exactly 16,384 px, at 39,993,344 px' => [2_441, 16_384];
    }

    /**
     * The picture only claims its size, so FakeImageEncoder cannot decode it: reaching the encoder is what is checked.
     */
    #[Test]
    #[DataProvider('picturesAtThePolicyLimits')]
    public function aPictureAtThePixelOrSideLimitPassesTheHeaderChecksAndReachesTheEncoder(int $width, int $height): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::pngClaiming($width, $height), 'image/png');

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::Unreadable, expectedEncodeCount: 1);
    }

    #[Test]
    public function aFileThatHasAGoodHeaderButDoesNotDecodeIsRejectedAsUnreadableAndNothingIsStored(): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::pngClaiming(100, 100), 'image/png');

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::Unreadable, expectedEncodeCount: 1);
    }

    #[Test]
    public function aFileLargerThanTheLeadingBytesIsCheckedFromItsFirstMegabyteAndThenReadWholeOnce(): void
    {
        $png = ImageFixtures::png(16, 16);
        $original = ImageFixtures::withAppended($png, str_repeat('x', ImageHeaderReader::LEADING_BYTES));
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');

        $this->clean(self::FIRST_MEDIA);

        self::assertSame(MediaStatus::Ready, $this->stateOf(self::FIRST_MEDIA)[0]);
        $key = $this->uploadKey(self::FIRST_MEDIA);
        self::assertSame([[$key, ImageHeaderReader::LEADING_BYTES], [$key, \strlen($original) + 1]], $this->mediaFiles->reads());
    }

    /**
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function replacementsAfterTheHeaderWasRead(): iterable
    {
        yield 'a longer file' => [static fn (string $original): string => $original.'x'];
        yield 'a shorter file' => [static fn (string $original): string => substr($original, 0, -1)];
        yield 'a file of the same size with another start' => [static fn (string $original): string => ImageFixtures::png(9, 9).str_repeat('x', max(0, \strlen($original) - \strlen(ImageFixtures::png(9, 9))))];
    }

    /**
     * @param \Closure(string): string $replacement
     */
    #[Test]
    #[DataProvider('replacementsAfterTheHeaderWasRead')]
    public function anObjectReplacedBetweenTheHeaderReadAndTheWholeReadIsRejectedAsChangedAndNothingIsEncoded(\Closure $replacement): void
    {
        $original = ImageFixtures::png(16, 16).str_repeat('y', 200);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $key = $this->uploadKey(self::FIRST_MEDIA);
        $this->mediaFiles->afterRead = function (int $readCount) use ($key, $replacement, $original): void {
            if (1 === $readCount) {
                $this->mediaFiles->put($key, $replacement($original));
            }
        };

        $this->clean(self::FIRST_MEDIA);

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::ChangedWhileChecked);
    }

    #[Test]
    public function aSecondUploadOfTheSamePictureByTheSameOrganizationReusesTheStoredVariantsAndEncodesNothing(): void
    {
        $original = ImageFixtures::png(300, 200);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $firstState = $this->stateOf(self::FIRST_MEDIA);
        $storedAfterFirst = $this->mediaFiles->keys();
        $this->upload(self::SECOND_MEDIA, $original, 'image/png');

        $this->clean(self::SECOND_MEDIA, 'message-2');

        self::assertSame($firstState, $this->stateOf(self::SECOND_MEDIA));
        self::assertSame(1, $this->imageEncoder->encodeCount);
        self::assertSame($storedAfterFirst, $this->mediaFiles->keys(), 'No variant was written for the second upload, and its upload object is deleted.');
        self::assertEquals(
            [new MediaReady(self::ACME_FOODS, self::FIRST_MEDIA, new \DateTimeImmutable(self::NOW)), new MediaReady(self::ACME_FOODS, self::SECOND_MEDIA, new \DateTimeImmutable(self::NOW))],
            $this->mediaEvents->published(),
        );
    }

    #[Test]
    public function theSamePictureOfAnotherOrganizationIsEncodedAgainAndNeverSharedAcrossTenants(): void
    {
        $original = ImageFixtures::png(300, 200);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png', organizationId: self::OTHER_ORGANIZATION);
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));
        $this->clean(self::FIRST_MEDIA, 'message-1', self::OTHER_ORGANIZATION);
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->upload(self::SECOND_MEDIA, $original, 'image/png');

        $this->clean(self::SECOND_MEDIA, 'message-2');

        self::assertSame(2, $this->imageEncoder->encodeCount);
        $acmeVariants = $this->stateOf(self::SECOND_MEDIA)[5];
        self::assertIsArray($acmeVariants);
        foreach ($acmeVariants as $key) {
            self::assertStringStartsWith('media/'.self::ACME_FOODS.'/', $key);
            self::assertTrue($this->mediaFiles->has($key));
        }
        self::assertCount(4, $this->mediaFiles->keys());
    }

    #[Test]
    public function aDifferentPictureOfTheSameSizeIsNotMistakenForADuplicate(): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::png(300, 200), 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $this->upload(self::SECOND_MEDIA, ImageFixtures::withAppended(ImageFixtures::png(300, 200), 'one more byte'), 'image/png');

        $this->clean(self::SECOND_MEDIA, 'message-2');

        self::assertSame(2, $this->imageEncoder->encodeCount);
        self::assertNotSame($this->stateOf(self::FIRST_MEDIA)[5], $this->stateOf(self::SECOND_MEDIA)[5]);
    }

    #[Test]
    public function aRejectedPictureIsNotReusedByALaterUploadOfTheSameBytes(): void
    {
        $animated = ImageFixtures::animatedPng(32, 32);
        $this->upload(self::FIRST_MEDIA, $animated, 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $this->upload(self::SECOND_MEDIA, $animated, 'image/png');

        $this->clean(self::SECOND_MEDIA, 'message-2');

        self::assertSame(MediaStatus::Rejected, $this->stateOf(self::SECOND_MEDIA)[0]);
        self::assertSame(MediaRejectReason::Animated->value, $this->stateOf(self::SECOND_MEDIA)[1]);
    }

    #[Test]
    public function aRedeliveredMessageDoesNothingAndLeavesAnObjectPutAtTheUploadKeyAlone(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $stateAfterFirst = $this->stateOf(self::FIRST_MEDIA);
        $keysAfterFirst = $this->mediaFiles->keys();
        $this->mediaFiles->put($this->uploadKey(self::FIRST_MEDIA), 'a file that must stay');

        $this->clean(self::FIRST_MEDIA, 'message-1');

        self::assertSame($stateAfterFirst, $this->stateOf(self::FIRST_MEDIA));
        self::assertSame('a file that must stay', $this->mediaFiles->bytesOf($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame(array_merge($keysAfterFirst, [$this->uploadKey(self::FIRST_MEDIA)]), $this->mediaFiles->keys());
        self::assertCount(1, $this->mediaEvents->published());
        self::assertSame(1, $this->imageEncoder->encodeCount);
    }

    #[Test]
    public function aNewMessageForAnObjectThatIsReadyOrRejectedAlreadyChangesNothingAndPublishesNothing(): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::png(64, 64), 'image/png');
        $this->upload(self::SECOND_MEDIA, ImageFixtures::animatedPng(8, 8), 'image/png');
        $this->clean(self::FIRST_MEDIA, 'message-1');
        $this->clean(self::SECOND_MEDIA, 'message-2');
        $states = [$this->stateOf(self::FIRST_MEDIA), $this->stateOf(self::SECOND_MEDIA)];
        $eventCount = \count($this->mediaEvents->published());
        $this->mediaFiles->put($this->uploadKey(self::FIRST_MEDIA), 'left alone');
        $this->mediaFiles->put($this->uploadKey(self::SECOND_MEDIA), 'left alone too');

        $this->clean(self::FIRST_MEDIA, 'message-3');
        $this->clean(self::SECOND_MEDIA, 'message-4');

        self::assertSame($states, [$this->stateOf(self::FIRST_MEDIA), $this->stateOf(self::SECOND_MEDIA)]);
        self::assertCount($eventCount, $this->mediaEvents->published());
        self::assertSame('left alone', $this->mediaFiles->bytesOf($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame('left alone too', $this->mediaFiles->bytesOf($this->uploadKey(self::SECOND_MEDIA)));
    }

    #[Test]
    public function aMessageForAnotherTenantThanTheCurrentOneIsRefusedAndChangesNothing(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));

        try {
            $this->clean(self::FIRST_MEDIA, 'message-1', self::ACME_FOODS);
            self::fail('An upload was cleaned under another tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('An upload is cleaned under the tenant of its own organization.', $refusal->getMessage());
        }

        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    #[Test]
    public function aMessageWithNoTenantIsRefusedAndChangesNothing(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->tenantContext->clear();

        try {
            $this->clean(self::FIRST_MEDIA, 'message-1');
            self::fail('An upload was cleaned with no tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('An upload is cleaned under the tenant of its own organization.', $refusal->getMessage());
        }

        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    #[Test]
    public function aMediaObjectOfAnotherOrganizationIsNotFoundForThisOnesMessageAndStaysUntouched(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png', organizationId: self::OTHER_ORGANIZATION);

        try {
            $this->clean(self::FIRST_MEDIA, 'message-1');
            self::fail('A media object of another organization was cleaned.');
        } catch (\LogicException $refusal) {
            self::assertSame('The media object of the upload to clean is not found.', $refusal->getMessage());
        }

        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
    }

    #[Test]
    public function anUnknownMediaObjectIsRefusedAndPublishesNothing(): void
    {
        try {
            $this->clean(self::UNKNOWN_MEDIA);
            self::fail('A media object that does not exist was cleaned.');
        } catch (\LogicException $refusal) {
            self::assertSame('The media object of the upload to clean is not found.', $refusal->getMessage());
        }

        self::assertSame([], $this->mediaEvents->published());
        self::assertSame([], $this->mediaObjects->storedRows());
    }

    #[Test]
    public function anUnreachableStorageFailsTheMessageForARetryAndTheRetryOfTheSameMessageThenSucceeds(): void
    {
        $original = ImageFixtures::png(64, 64);
        $this->upload(self::FIRST_MEDIA, $original, 'image/png');
        $this->mediaFiles->isUnreachable = true;

        try {
            $this->clean(self::FIRST_MEDIA, 'message-1');
            self::fail('A storage that could not be reached looked like a missing object.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The storage cannot be reached.', $failure->getMessage());
        }

        $this->mediaFiles->isUnreachable = false;
        $this->assertNothingChangedFor(self::FIRST_MEDIA, $original);
        $this->clean(self::FIRST_MEDIA, 'message-1');
        self::assertSame(MediaStatus::Ready, $this->stateOf(self::FIRST_MEDIA)[0]);
    }

    #[Test]
    public function aCommitThatFailsAfterTheUploadWasDeletedLeavesTheObjectPendingAndTheRetryRejectsItAsMissing(): void
    {
        $this->upload(self::FIRST_MEDIA, ImageFixtures::png(64, 64), 'image/png');
        $this->transactions->nextCommitFailure = new \RuntimeException('could not commit');

        try {
            $this->clean(self::FIRST_MEDIA, 'message-1');
            self::fail('The commit failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('could not commit', $failure->getMessage());
        }

        self::assertSame([MediaStatus::Pending, null, null, null, null, null], $this->stateOf(self::FIRST_MEDIA));
        self::assertSame([], $this->mediaEvents->published(), 'The event rolled back with the change.');
        self::assertFalse($this->mediaFiles->has($this->uploadKey(self::FIRST_MEDIA)), 'The bucket is not part of the transaction.');

        $this->clean(self::FIRST_MEDIA, 'message-1');

        $this->assertRejected(self::FIRST_MEDIA, MediaRejectReason::Missing, expectedEncodeCount: 1, keepsVariants: true);
    }

    #[Test]
    public function everyRejectionReasonIsADistinctPlainTextThatFitsTheMediaObjectsLimit(): void
    {
        $reasons = array_map(static fn (MediaRejectReason $reason): string => $reason->value, MediaRejectReason::cases());

        self::assertCount(\count(array_unique($reasons)), $reasons);
        foreach ($reasons as $reason) {
            self::assertGreaterThan(0, mb_strlen($reason));
            self::assertLessThanOrEqual(MediaObject::MAX_REJECT_REASON_LENGTH, mb_strlen($reason));
            self::assertSame($reason, trim($reason));
        }
    }

    private function cleanUploadWith(ImageEncoder $imageEncoder): CleanUpload
    {
        return new CleanUpload($this->onceOnlyHandling, $this->mediaObjects, $this->mediaFiles, new PurePhpImageHeaderReader(), $imageEncoder, $this->mediaEvents, $this->tenantContext);
    }

    /**
     * @param \Closure(string): EncodedImage $encoded gets the original and returns what the encoder answers
     */
    private function encoderReturning(\Closure $encoded): ImageEncoder
    {
        return new readonly class($encoded) implements ImageEncoder {
            /** @param \Closure(string): EncodedImage $encoded */
            public function __construct(private \Closure $encoded)
            {
            }

            public function encode(string $original, ImageFormat $format): EncodedImage
            {
                return ($this->encoded)($original);
            }
        };
    }

    private function clean(string $mediaId, string $messageId = 'message-1', string $organizationId = self::ACME_FOODS): void
    {
        $this->onceOnlyHandling->deliver($messageId);
        $this->cleanUpload->__invoke(new ProcessUpload($organizationId, $mediaId), new \DateTimeImmutable(self::NOW));
    }

    private function cleanWith(ImageEncoder $imageEncoder, string $mediaId, string $messageId = 'message-1'): void
    {
        $this->onceOnlyHandling->deliver($messageId);
        $this->cleanUploadWith($imageEncoder)->__invoke(new ProcessUpload(self::ACME_FOODS, $mediaId), new \DateTimeImmutable(self::NOW));
    }

    private static function riffContainerLengthOf(string $webp): int
    {
        $riffSize = unpack('V', $webp, 4);
        self::assertIsArray($riffSize);
        $size = $riffSize[1] ?? null;
        self::assertIsInt($size);

        return 8 + $size;
    }

    private function upload(string $mediaId, string $bytes, string $mime, ?int $declaredBytes = null, string $organizationId = self::ACME_FOODS): void
    {
        $this->addPendingObject($mediaId, $mime, $declaredBytes ?? max(1, \strlen($bytes)), $organizationId);
        $this->mediaFiles->put($this->uploadKey($mediaId, $organizationId), $bytes);
    }

    private function addPendingObject(string $mediaId, string $mime, int $declaredBytes, string $organizationId = self::ACME_FOODS): void
    {
        $previousTenant = $this->tenantContext->get();
        $this->tenantContext->set(new OrganizationId($organizationId));
        $mediaObject = MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), $this->uploadKey($mediaId, $organizationId), $mime, $declaredBytes, new UserId(self::MIA), new \DateTimeImmutable(self::UPLOADED_AT));
        $this->transactions->run(fn () => $this->mediaObjects->add($mediaObject));
        if (null !== $previousTenant) {
            $this->tenantContext->set($previousTenant);
        }
    }

    private function uploadKey(string $mediaId, string $organizationId = self::ACME_FOODS): string
    {
        return 'uploads/'.$organizationId.'/'.$mediaId;
    }

    private function variantKey(string $mediaId, string $variant): string
    {
        return 'media/'.self::ACME_FOODS.'/2026/03/'.$mediaId.'/'.$variant.'.webp';
    }

    /**
     * @return array{ImageFormat, int, int}
     */
    private function webpHeaderOf(string $key): array
    {
        $header = new PurePhpImageHeaderReader()->read($this->mediaFiles->bytesOf($key));
        self::assertFalse($header->isAnimated);

        return [$header->format, $header->width, $header->height];
    }

    /**
     * @return array{MediaStatus, string|null, string|null, int|null, int|null, array<string, string>|null}
     */
    private function stateOf(string $mediaId): array
    {
        foreach ($this->mediaObjects->storedRows() as $mediaObject) {
            if ($mediaObject->id()->toString() === $mediaId) {
                return [$mediaObject->status(), $mediaObject->rejectReason(), $mediaObject->sha256(), $mediaObject->width(), $mediaObject->height(), $mediaObject->variants()];
            }
        }

        throw new \LogicException('No media object '.$mediaId);
    }

    /**
     * The object is rejected with the reason and nothing else changed: no hash, size or variant on the row, the upload
     * deleted, one MediaRejected, and no variant in the bucket.
     */
    private function assertRejected(string $mediaId, MediaRejectReason $reason, int $expectedEncodeCount = 0, bool $keepsVariants = false): void
    {
        self::assertSame([MediaStatus::Rejected, $reason->value, null, null, null, null], $this->stateOf($mediaId));
        self::assertFalse($this->mediaFiles->has($this->uploadKey($mediaId)), 'The upload object was not deleted.');
        if (!$keepsVariants) {
            self::assertSame([], $this->mediaFiles->keys(), 'Something was stored for a rejected upload.');
        }
        self::assertEquals(
            [new MediaRejected(self::ACME_FOODS, $mediaId, $reason->value, new \DateTimeImmutable(self::NOW))],
            $this->mediaEvents->published(),
        );
        self::assertSame($expectedEncodeCount, $this->imageEncoder->encodeCount);
    }

    private function assertNothingChangedFor(string $mediaId, string $uploadedBytes): void
    {
        self::assertSame([MediaStatus::Pending, null, null, null, null, null], $this->stateOf($mediaId));
        self::assertSame($uploadedBytes, $this->mediaFiles->bytesOf($this->uploadKey($mediaId, $this->organizationOf($mediaId))), 'The upload object was changed or deleted.');
        self::assertSame([$this->uploadKey($mediaId, $this->organizationOf($mediaId))], $this->mediaFiles->keys(), 'Something was stored.');
        self::assertSame([], $this->mediaEvents->published());
    }

    private function organizationOf(string $mediaId): string
    {
        foreach ($this->mediaObjects->storedRows() as $mediaObject) {
            if ($mediaObject->id()->toString() === $mediaId) {
                return $mediaObject->organizationId()->toString();
            }
        }

        throw new \LogicException('No media object '.$mediaId);
    }
}
