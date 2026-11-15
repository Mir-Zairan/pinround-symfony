<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

use App\Media\Api\Event\MediaReady;
use App\Media\Api\Event\MediaRejected;
use App\Media\Application\ProcessUpload;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaRejectReason;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\MediaVariant;
use App\Media\Domain\MediaVariantKey;
use App\Media\Domain\UploadPolicy;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Handles a ProcessUpload in worker-media, in the bus's transaction under the media object's organization
 * (media-pipeline.md, Processing worker). It checks the uploaded file from the cheapest check to the dearest: its size
 * from a HEAD, then its signature, declared type, dimensions and animation from at most
 * ImageHeaderReader::LEADING_BYTES, and only then reads it whole. A picture the organization has ready already, by the
 * hash of the original, reuses that object's variants; any other is re-encoded by the ImageEncoder. Either way the
 * media object ends ready or rejected with a plain reason, the upload file is deleted and MediaReady or MediaRejected
 * goes to the outbox.
 *
 * It runs once per message (OnceOnlyHandling) and only on a pending object, which it locks. The bucket is not part of
 * the transaction: a commit that fails after the upload file was deleted makes the retry reject the object as missing.
 */
final readonly class CleanUpload
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered messages would clean again. */
    public const string HANDLER_NAME = 'media.clean_upload';

    private const string WEBP = 'image/webp';

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private MediaObjects $mediaObjects,
        private MediaFiles $mediaFiles,
        private ImageHeaderReader $imageHeaderReader,
        private ImageEncoder $imageEncoder,
        private MediaEvents $mediaEvents,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @throws \LogicException   when the message's organization is not the tenant, its media object is not found, or
     *                           the encoder breaks its contract; nothing is stored then
     * @throws \RuntimeException when the bucket cannot be reached or the encoder cannot run, so the message is retried
     *                           and the object stays pending
     */
    public function __invoke(ProcessUpload $request, \DateTimeImmutable $now): void
    {
        $organizationId = new OrganizationId($request->organizationId);
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('An upload is cleaned under the tenant of its own organization.');
        }
        $mediaId = new MediaId($request->mediaId);

        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($mediaId, $now): void {
            $mediaObject = $this->mediaObjects->lockById($mediaId) ?? throw new \LogicException('The media object of the upload to clean is not found.');
            if (MediaStatus::Pending !== $mediaObject->status()) {
                return;
            }

            $checked = $this->checkOriginal($mediaObject);
            $rejectReason = $checked instanceof MediaRejectReason ? $checked : $this->markReady($mediaObject, $checked);
            if (null !== $rejectReason) {
                $mediaObject->reject($rejectReason->value);
            }
            $this->mediaObjects->save($mediaObject);
            $this->mediaFiles->delete($mediaObject->storageKey());
            $this->mediaEvents->publish(null === $rejectReason
                ? new MediaReady($mediaObject->organizationId()->toString(), $mediaObject->id()->toString(), $now)
                : new MediaRejected($mediaObject->organizationId()->toString(), $mediaObject->id()->toString(), $rejectReason->value, $now));
        });
    }

    private function checkOriginal(MediaObject $mediaObject): CheckedOriginal|MediaRejectReason
    {
        $uploadKey = $mediaObject->storageKey();
        $size = $this->mediaFiles->sizeOf($uploadKey);
        if (null === $size) {
            return MediaRejectReason::Missing;
        }
        if ($size > UploadPolicy::MAX_BYTES) {
            return MediaRejectReason::TooLarge;
        }
        if ($size !== $mediaObject->bytes()) {
            return MediaRejectReason::WrongSize;
        }

        $leadingBytes = $this->mediaFiles->readUpTo($uploadKey, min(ImageHeaderReader::LEADING_BYTES, $size));
        try {
            $header = $this->imageHeaderReader->read($leadingBytes);
        } catch (UnknownImageFormat) {
            return MediaRejectReason::NotAnImage;
        } catch (UnreadableImageHeader) {
            return MediaRejectReason::Unreadable;
        }
        if ($header->format->value !== $mediaObject->mime()) {
            return MediaRejectReason::WrongType;
        }
        if ($header->isAnimated) {
            return MediaRejectReason::Animated;
        }
        if (!UploadPolicy::allowsDimensions($header->width, $header->height)) {
            return MediaRejectReason::TooManyPixels;
        }

        // One byte more than the HEAD gave, so a file replaced since then shows as longer instead of being cut short.
        $original = $this->mediaFiles->readUpTo($uploadKey, $size + 1);
        if (\strlen($original) !== $size || !str_starts_with($original, $leadingBytes)) {
            return MediaRejectReason::ChangedWhileChecked;
        }

        return new CheckedOriginal($original, $header->format);
    }

    /**
     * @return MediaRejectReason|null why the encoder could not use the original; null when the object is ready
     */
    private function markReady(MediaObject $mediaObject, CheckedOriginal $original): ?MediaRejectReason
    {
        $sha256 = hash('sha256', $original->bytes);
        $sameReady = $this->mediaObjects->readyBySha256($sha256);
        if (null !== $sameReady) {
            $width = $sameReady->width();
            $height = $sameReady->height();
            $variants = $sameReady->variants();
            if (null === $width || null === $height || null === $variants) {
                throw new \LogicException('A ready media object has a width, a height and variants.');
            }
            $mediaObject->markReady($sha256, $width, $height, $variants);

            return null;
        }

        try {
            $encoded = $this->imageEncoder->encode($original->bytes, $original->format);
        } catch (UndecodableImage) {
            return MediaRejectReason::Unreadable;
        }
        $this->requireWebp($encoded->view, ImageEncoder::VIEW_MAX_WIDTH, $original->bytes);
        $this->requireWebp($encoded->thumb, ImageEncoder::THUMB_MAX_WIDTH, $original->bytes);

        $viewKey = MediaVariantKey::of($mediaObject->organizationId(), $mediaObject->id(), $mediaObject->createdAt(), MediaVariant::View)->toString();
        $thumbKey = MediaVariantKey::of($mediaObject->organizationId(), $mediaObject->id(), $mediaObject->createdAt(), MediaVariant::Thumb)->toString();
        $this->mediaFiles->write($viewKey, $encoded->view->bytes, self::WEBP);
        $this->mediaFiles->write($thumbKey, $encoded->thumb->bytes, self::WEBP);
        $mediaObject->markReady($sha256, $encoded->view->width, $encoded->view->height, [
            MediaVariant::View->value => $viewKey,
            MediaVariant::Thumb->value => $thumbKey,
        ]);

        return null;
    }

    /**
     * Only a still WebP of the stated size, no wider than its maximum, exactly as long as its RIFF container and not the
     * original itself is ever stored, whatever the encoder returns: the header alone would let an encoder that passed a
     * WebP polyglot through store its tail.
     */
    private function requireWebp(EncodedVariant $variant, int $maxWidth, string $original): void
    {
        try {
            $header = $this->imageHeaderReader->read(substr($variant->bytes, 0, ImageHeaderReader::LEADING_BYTES));
        } catch (UnknownImageFormat|UnreadableImageHeader $unreadable) {
            throw new \LogicException('The image encoder returned a variant that is no readable WebP.', 0, $unreadable);
        }
        if (ImageFormat::Webp !== $header->format || $header->isAnimated || $header->width !== $variant->width || $header->height !== $variant->height || $variant->width > $maxWidth) {
            throw new \LogicException(\sprintf('The image encoder returned a variant that is not a still WebP of its stated size, at most %d px wide.', $maxWidth));
        }
        // The RIFF size counts every byte after its own field (RFC 9649, 2.5); the header reader confirmed the 12 bytes.
        $riffSize = unpack('V', $variant->bytes, 4);
        if (false === $riffSize || !\is_int($riffSize[1] ?? null) || 8 + $riffSize[1] !== \strlen($variant->bytes)) {
            throw new \LogicException('The image encoder returned a variant whose length is not that of its WebP container.');
        }
        if ($variant->bytes === $original) {
            throw new \LogicException('The image encoder returned the original as a variant.');
        }
    }
}
