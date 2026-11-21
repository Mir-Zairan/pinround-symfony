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
use App\Media\Domain\UploadPolicy;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Handles a ProcessUpload in worker-media, in the bus's transaction under the media object's organization
 * (media-pipeline.md, Processing worker). It checks the uploaded file from the cheapest check to the dearest: its size
 * from a HEAD, then its signature, declared type, dimensions and animation from at most
 * ImageHeaderReader::LEADING_BYTES, and only then reads it whole. A picture the organization has ready already, by the
 * hash of the original, reuses that object's variants; any other is re-encoded (ReadyVariants). Either way the
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

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private MediaObjects $mediaObjects,
        private MediaFiles $mediaFiles,
        private ImageHeaderReader $imageHeaderReader,
        private ReadyVariants $readyVariants,
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
            $rejectReason = $checked instanceof MediaRejectReason ? $checked : $this->readyVariants->markReady($mediaObject, $checked);
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
}
