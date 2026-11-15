<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaStatus;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MediaObjectTest extends TestCase
{
    private const string MEDIA = '0192f3a0-bbbb-7bbb-8ccc-ddddeeeeffff';
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';
    private const string USER = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02';
    private const string SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    #[Test]
    public function anUploadedObjectIsPendingWithNothingFromProcessing(): void
    {
        $createdAt = new MockClock('2026-03-02T10:00:00Z')->now();

        $media = MediaObject::upload(new MediaId(self::MEDIA), new OrganizationId(self::ORGANIZATION), 'uploads/org/intent', 'image/png', 2048, new UserId(self::USER), $createdAt);

        self::assertSame(self::MEDIA, $media->id()->toString());
        self::assertSame(self::ORGANIZATION, $media->organizationId()->toString());
        self::assertSame('uploads/org/intent', $media->storageKey());
        self::assertSame('image/png', $media->mime());
        self::assertSame(2048, $media->bytes());
        self::assertSame(self::USER, $media->uploadedBy()->toString());
        self::assertSame($createdAt, $media->createdAt());
        $this->assertStillPending($media);
    }

    #[Test]
    public function anUploadWithoutStorageKeyMediaTypeOrSizeIsRefused(): void
    {
        $cases = [
            'A media object needs a storage key.' => ['', 'image/png', 1],
            'A media object needs a media type.' => ['key', '', 1],
            'A media object needs a size of at least one byte.' => ['key', 'image/png', 0],
        ];

        foreach ($cases as $reason => [$storageKey, $mime, $bytes]) {
            try {
                MediaObject::upload(new MediaId(self::MEDIA), new OrganizationId(self::ORGANIZATION), $storageKey, $mime, $bytes, new UserId(self::USER), new \DateTimeImmutable('2026-03-02T10:00:00Z'));
                self::fail('Not refused: '.$reason);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($reason, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function markingReadyKeepsTheHashSizeAndVariants(): void
    {
        $media = $this->pending();

        $media->markReady(self::SHA256, 640, 480, ['view' => 'media/org/view.webp', 'thumb' => 'media/org/thumb.webp']);

        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(self::SHA256, $media->sha256());
        self::assertSame(640, $media->width());
        self::assertSame(480, $media->height());
        self::assertSame(['view' => 'media/org/view.webp', 'thumb' => 'media/org/thumb.webp'], $media->variants());
        self::assertNull($media->rejectReason());
    }

    #[Test]
    public function aHashThatIsNotSixtyFourLowercaseHexDigitsIsRefusedAndNothingChanges(): void
    {
        foreach (['', 'abc', strtoupper(self::SHA256), self::SHA256."\n", self::SHA256.'0', str_repeat('g', 64)] as $sha256) {
            $media = $this->pending();
            $this->assertMarkReadyRefused($media, $sha256, 1, 1, ['view' => 'k'], 'A media hash must be 64 lowercase hex digits.');
        }
    }

    #[Test]
    public function aSizeBelowOnePixelIsRefusedAndNothingChanges(): void
    {
        foreach ([[0, 480], [640, 0], [-1, -1]] as [$width, $height]) {
            $media = $this->pending();
            $this->assertMarkReadyRefused($media, self::SHA256, $width, $height, ['view' => 'k'], 'A media object needs a width and a height of at least one pixel.');
        }
    }

    #[Test]
    public function variantsWithoutEntriesOrWithAnEmptyNameOrKeyAreRefusedAndNothingChanges(): void
    {
        foreach ([[], ['' => 'key'], ['view' => '']] as $variants) {
            $media = $this->pending();
            $this->assertMarkReadyRefused($media, self::SHA256, 1, 1, $variants, 'A ready media object needs at least one variant, each with a name and a storage key.');
        }
    }

    #[Test]
    public function aReadyObjectCannotBeMarkedReadyAgainOrRejectedAndKeepsItsValues(): void
    {
        $media = $this->pending();
        $media->markReady(self::SHA256, 640, 480, ['view' => 'k']);

        try {
            $media->markReady(str_repeat('a', 64), 1, 1, ['other' => 'x']);
            self::fail('Not refused.');
        } catch (\LogicException $exception) {
            self::assertSame('The media object is ready already.', $exception->getMessage());
        }
        try {
            $media->reject('Too late');
            self::fail('Not refused.');
        } catch (\LogicException $exception) {
            self::assertSame('The media object is ready already.', $exception->getMessage());
        }

        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(self::SHA256, $media->sha256());
        self::assertSame(640, $media->width());
        self::assertSame(['view' => 'k'], $media->variants());
        self::assertNull($media->rejectReason());
    }

    #[Test]
    public function rejectingKeepsTheTrimmedReason(): void
    {
        $media = $this->pending();

        $media->reject("  Not a picture.\n");

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame('Not a picture.', $media->rejectReason());
        self::assertNull($media->sha256());
        self::assertNull($media->variants());
    }

    #[Test]
    public function aReasonOfTwoHundredMultibyteCharactersIsAcceptedAndOneMoreIsRefused(): void
    {
        $accepted = $this->pending();
        $accepted->reject(str_repeat('é', MediaObject::MAX_REJECT_REASON_LENGTH));
        self::assertSame(str_repeat('é', 200), $accepted->rejectReason());

        $this->assertRejectRefused($this->pending(), str_repeat('é', MediaObject::MAX_REJECT_REASON_LENGTH + 1));
    }

    #[Test]
    public function aBlankReasonIsRefusedAndNothingChanges(): void
    {
        foreach (['', '   ', "\n\t "] as $reason) {
            $this->assertRejectRefused($this->pending(), $reason);
        }
    }

    #[Test]
    public function aRejectedObjectCannotBeRejectedAgainOrMarkedReadyAndKeepsItsReason(): void
    {
        $media = $this->pending();
        $media->reject('Not a picture.');

        try {
            $media->reject('Another reason');
            self::fail('Not refused.');
        } catch (\LogicException $exception) {
            self::assertSame('The media object is rejected already.', $exception->getMessage());
        }
        try {
            $media->markReady(self::SHA256, 1, 1, ['view' => 'k']);
            self::fail('Not refused.');
        } catch (\LogicException $exception) {
            self::assertSame('The media object is rejected already.', $exception->getMessage());
        }

        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame('Not a picture.', $media->rejectReason());
        self::assertNull($media->sha256());
        self::assertNull($media->variants());
    }

    /**
     * @param array<string, string> $variants
     */
    private function assertMarkReadyRefused(MediaObject $media, string $sha256, int $width, int $height, array $variants, string $reason): void
    {
        try {
            $media->markReady($sha256, $width, $height, $variants);
            self::fail('Not refused: '.$reason);
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }

        $this->assertStillPending($media);
    }

    private function assertRejectRefused(MediaObject $media, string $reason): void
    {
        try {
            $media->reject($reason);
            self::fail('Not refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('A rejection needs a reason of 1 to 200 characters.', $exception->getMessage());
        }

        $this->assertStillPending($media);
    }

    private function assertStillPending(MediaObject $media): void
    {
        self::assertSame(MediaStatus::Pending, $media->status());
        self::assertNull($media->rejectReason());
        self::assertNull($media->sha256());
        self::assertNull($media->width());
        self::assertNull($media->height());
        self::assertNull($media->variants());
    }

    private function pending(): MediaObject
    {
        return MediaObject::upload(new MediaId(self::MEDIA), new OrganizationId(self::ORGANIZATION), 'uploads/org/intent', 'image/png', 2048, new UserId(self::USER), new MockClock('2026-03-02T10:00:00Z')->now());
    }
}
