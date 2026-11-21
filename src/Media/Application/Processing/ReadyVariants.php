<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaRejectReason;
use App\Media\Domain\MediaVariant;
use App\Media\Domain\MediaVariantKey;

/**
 * The last step of processing a picture whose original passed every check (media-pipeline.md, Processing worker): a
 * picture the organization has ready already, by the hash of the original, lends its variants; any other is
 * re-encoded by the ImageEncoder and its view and thumb are stored in the bucket. CleanUpload runs it in worker-media;
 * the dev demo seed runs it on a committed screenshot, so both store the same variants.
 */
final readonly class ReadyVariants
{
    private const string WEBP = 'image/webp';

    public function __construct(
        private MediaObjects $mediaObjects,
        private MediaFiles $mediaFiles,
        private ImageHeaderReader $imageHeaderReader,
        private ImageEncoder $imageEncoder,
    ) {
    }

    /**
     * Marks $mediaObject ready with its variants; it does not save it. The variants are written to the bucket before
     * the caller's transaction commits, so a rollback leaves them there for retention to remove.
     *
     * @return MediaRejectReason|null why the encoder could not use the original; null when the object is ready
     *
     * @throws \LogicException   when the encoder breaks its contract; nothing is stored then
     * @throws \RuntimeException when the bucket cannot be written or the encoder cannot run
     */
    public function markReady(MediaObject $mediaObject, CheckedOriginal $original): ?MediaRejectReason
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
