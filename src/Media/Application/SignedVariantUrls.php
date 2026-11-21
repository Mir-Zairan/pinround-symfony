<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\MediaVariant;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Signed GET URLs of the stored WebP variants, for a whole page of pictures at once; the contract is on
 * {@see SignedUrls}.
 */
final readonly class SignedVariantUrls implements SignedUrls
{
    public function __construct(
        private MediaObjects $mediaObjects,
        private MediaUrlSigner $mediaUrlSigner,
        private TenantContext $tenantContext,
    ) {
    }

    public function forVariants(OrganizationId $organizationId, array $mediaIds, string $variant, \DateTimeImmutable $at): array
    {
        $mediaVariant = MediaVariant::tryFrom($variant) ?? throw new \InvalidArgumentException('A picture variant is "view" or "thumb".');
        if (\count($mediaIds) > self::MAX_MEDIA_IDS) {
            throw new \InvalidArgumentException(\sprintf('Signed URLs are made for at most %d pictures at once.', self::MAX_MEDIA_IDS));
        }
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('Signed URLs are made under the tenant of their own organization.');
        }

        $askedIds = self::wellFormedIdsOf($mediaIds);
        if ([] === $askedIds) {
            return [];
        }
        $mediaObjectsById = [];
        foreach ($this->mediaObjects->byIds(array_values($askedIds)) as $mediaObject) {
            $mediaObjectsById[$mediaObject->id()->toString()] = $mediaObject;
        }

        $mediaImages = [];
        foreach (array_keys($askedIds) as $mediaId) {
            if (isset($mediaObjectsById[$mediaId])) {
                $mediaImages[$mediaId] = $this->imageOf($mediaObjectsById[$mediaId], $mediaVariant, $at);
            }
        }

        return $mediaImages;
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, MediaId> by the id in lowercase, each once, in the order given
     */
    private static function wellFormedIdsOf(array $mediaIds): array
    {
        $wellFormedIds = [];
        foreach ($mediaIds as $mediaId) {
            try {
                $id = new MediaId($mediaId);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $wellFormedIds[$id->toString()] ??= $id;
        }

        return $wellFormedIds;
    }

    private function imageOf(MediaObject $mediaObject, MediaVariant $variant, \DateTimeImmutable $at): MediaImage
    {
        $mediaId = $mediaObject->id()->toString();

        return match ($mediaObject->status()) {
            MediaStatus::Pending => MediaImage::pending($mediaId),
            MediaStatus::Rejected => MediaImage::rejected($mediaId, $mediaObject->rejectReason() ?? throw new \LogicException('A rejected media object has a reason.')),
            MediaStatus::Ready => $this->readyImageOf($mediaObject, self::shownVariantOf($mediaObject, $variant), $at),
        };
    }

    /**
     * The variant asked for, or the view when a thumb is asked for and none is stored: a smaller picture is an
     * optimisation, so a missing one shows the view rather than no picture.
     */
    private static function shownVariantOf(MediaObject $mediaObject, MediaVariant $variant): MediaVariant
    {
        return MediaVariant::Thumb === $variant && !isset($mediaObject->variants()[MediaVariant::Thumb->value]) ? MediaVariant::View : $variant;
    }

    private function readyImageOf(MediaObject $mediaObject, MediaVariant $variant, \DateTimeImmutable $at): MediaImage
    {
        [$width, $height] = self::sizeOf($mediaObject, $variant);

        return MediaImage::ready($mediaObject->id()->toString(), $this->mediaUrlSigner->forGet($this->storageKeyOf($mediaObject, $variant), $at), $width, $height);
    }

    /**
     * Only the view's size is stored. The thumb is encoded from the same original by ImageEncoder's rule (scaled down
     * to THUMB_MAX_WIDTH, never up, aspect ratio kept), so its size follows from the view's, within a pixel of rounding.
     *
     * @return array{int|null, int|null}
     */
    private static function sizeOf(MediaObject $mediaObject, MediaVariant $variant): array
    {
        $viewWidth = $mediaObject->width();
        $viewHeight = $mediaObject->height();
        if (MediaVariant::View === $variant || null === $viewWidth || null === $viewHeight) {
            return [$viewWidth, $viewHeight];
        }
        $thumbWidth = min($viewWidth, ImageEncoder::THUMB_MAX_WIDTH);

        return [$thumbWidth, max(1, (int) round($viewHeight * $thumbWidth / $viewWidth))];
    }

    /**
     * The key stored at markReady(), not one built again: a deduplicated object points at the variants of the first.
     */
    private function storageKeyOf(MediaObject $mediaObject, MediaVariant $variant): string
    {
        $storageKey = $mediaObject->variants()[$variant->value] ?? null;
        if (null === $storageKey) {
            throw new \LogicException(\sprintf('The ready media object %s has no %s variant stored.', $mediaObject->id()->toString(), $variant->value));
        }

        return $storageKey;
    }
}
