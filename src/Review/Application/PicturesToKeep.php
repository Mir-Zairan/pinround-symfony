<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Whether pictures may be kept in a report draft or a notebook: each one the person uploaded in the organization and
 * not rejected. Unlike ReportPictures, a picture still processing is kept, as an agent adds it right after its upload.
 */
final readonly class PicturesToKeep
{
    public function __construct(private UploadedMedia $uploadedMedia)
    {
    }

    /**
     * One read for all pictures, none for an empty list, inside the caller's transaction whose tenant is
     * $organizationId.
     *
     * @param list<string> $mediaIds in any case; at most UploadedMedia::MAX_MEDIA_IDS
     *
     * @return string|null the RoundRefused MEDIA_NOT_UPLOADED_BY_YOU or MEDIA_REJECTED message of the first refused
     *                     picture in the list, or null when every one may be kept
     *
     * @throws \InvalidArgumentException when $mediaIds holds more than UploadedMedia::MAX_MEDIA_IDS entries
     * @throws \LogicException           when no tenant is set, it is not $organizationId, or no transaction is open
     */
    public function refusalOf(OrganizationId $organizationId, UserId $uploaderId, array $mediaIds): ?string
    {
        if ([] === $mediaIds) {
            return null;
        }
        $states = $this->uploadedMedia->statesOf($organizationId, $uploaderId, $mediaIds);
        foreach ($mediaIds as $mediaId) {
            $refusal = match ($states[strtolower($mediaId)] ?? null) {
                null => RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU,
                MediaImageStatus::Rejected => RoundRefused::MEDIA_REJECTED,
                MediaImageStatus::Pending, MediaImageStatus::Ready => null,
            };
            if (null !== $refusal) {
                return $refusal;
            }
        }

        return null;
    }
}
