<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaStatus;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The processing state of what a person uploaded; the contract is on {@see UploadedMedia}.
 */
final readonly class UploaderMediaStates implements UploadedMedia
{
    public function __construct(
        private MediaObjects $mediaObjects,
        private TenantContext $tenantContext,
    ) {
    }

    public function statesOf(OrganizationId $organizationId, UserId $uploader, array $mediaIds): array
    {
        if (\count($mediaIds) > self::MAX_MEDIA_IDS) {
            throw new \InvalidArgumentException(\sprintf('The state is read of at most %d uploads at once.', self::MAX_MEDIA_IDS));
        }
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('An upload state is read under the tenant of its own organization.');
        }

        $askedIds = [];
        foreach ($mediaIds as $mediaId) {
            try {
                $id = new MediaId($mediaId);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $askedIds[$id->toString()] ??= $id;
        }

        $states = [];
        foreach ($this->mediaObjects->byIds(array_values($askedIds)) as $mediaObject) {
            if ($mediaObject->uploadedBy()->equals($uploader)) {
                $states[$mediaObject->id()->toString()] = match ($mediaObject->status()) {
                    MediaStatus::Pending => MediaImageStatus::Pending,
                    MediaStatus::Ready => MediaImageStatus::Ready,
                    MediaStatus::Rejected => MediaImageStatus::Rejected,
                };
            }
        }

        return $states;
    }
}
