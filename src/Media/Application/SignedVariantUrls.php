<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Api\MediaImage;
use App\Media\Api\SignedUrls;
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
            MediaStatus::Ready => MediaImage::ready(
                $mediaId,
                $this->mediaUrlSigner->forGet($this->storageKeyOf($mediaObject, $variant), $at),
                // The stored size is the view's; the thumb's own is not kept.
                MediaVariant::View === $variant ? $mediaObject->width() : null,
                MediaVariant::View === $variant ? $mediaObject->height() : null,
            ),
        };
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
