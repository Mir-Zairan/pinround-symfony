<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Like SignedVariantUrls: refuses a variant other than view or thumb, more than MAX_MEDIA_IDS ids and an organization
 * that is not the tenant's, leaves out another organization's and an unknown id, answers by the id in lowercase, and signs a ready picture for the start of $at's minute. Records every
 * list of ids, the variant and the signing time it is asked for.
 */
final class RecordingSignedUrls implements SignedUrls
{
    /** @var list<array{string, string, string}> */
    public array $asked = [];

    /**
     * @param list<array{string, string, MediaImageStatus}> $media organization, media id in lowercase, state
     */
    public function __construct(private readonly TenantContext $tenantContext, private readonly array $media)
    {
    }

    public function forVariants(OrganizationId $organizationId, array $mediaIds, string $variant, \DateTimeImmutable $at): array
    {
        if (!\in_array($variant, ['view', 'thumb'], true)) {
            throw new \InvalidArgumentException('A picture variant is "view" or "thumb".');
        }
        if (\count($mediaIds) > self::MAX_MEDIA_IDS) {
            throw new \InvalidArgumentException(\sprintf('Signed URLs are made for at most %d pictures at once.', self::MAX_MEDIA_IDS));
        }
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('Pictures are signed under the tenant of their own organization.');
        }
        $signedAt = $at->setTime((int) $at->format('H'), (int) $at->format('i'))->format(\DATE_ATOM);
        $images = [];
        foreach ($mediaIds as $mediaId) {
            $this->asked[] = [$mediaId, $variant, $signedAt];
            foreach ($this->media as [$mediaOrganizationId, $id, $state]) {
                if ($mediaOrganizationId !== $organizationId->toString() || $id !== strtolower($mediaId)) {
                    continue;
                }
                $images[$id] = match ($state) {
                    MediaImageStatus::Ready => MediaImage::ready($id, 'https://storage.test/'.$variant.'/'.$id.'?at='.$signedAt, 1600, 900),
                    MediaImageStatus::Pending => MediaImage::pending($id),
                    MediaImageStatus::Rejected => MediaImage::rejected($id, 'We could not process this image. Try again.'),
                };
            }
        }

        return $images;
    }
}
