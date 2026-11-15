<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Whether pictures may go in a report: each one the reporter uploaded in the organization, and processed. The one rule
 * both createReport and the new ticket form's check before the ticket ask.
 */
final readonly class ReportPictures
{
    public function __construct(private UploadedMedia $uploadedMedia)
    {
    }

    /**
     * One read for all pictures, inside the caller's transaction whose tenant is $organizationId.
     *
     * @param list<string> $mediaIds as posted, in any case; at most UploadedMedia::MAX_MEDIA_IDS
     *
     * @return string|null the RoundRefused MEDIA_ message of the first refused picture in the list, or null when every
     *                     one may go in
     *
     * @throws \InvalidArgumentException when $mediaIds holds more than UploadedMedia::MAX_MEDIA_IDS entries
     * @throws \LogicException           when no tenant is set, it is not $organizationId, or no transaction is open
     */
    public function refusalOf(OrganizationId $organizationId, UserId $reporterId, array $mediaIds): ?string
    {
        $states = $this->uploadedMedia->statesOf($organizationId, $reporterId, $mediaIds);
        foreach ($mediaIds as $mediaId) {
            $refusal = match ($states[strtolower($mediaId)] ?? null) {
                null => RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU,
                MediaImageStatus::Pending => RoundRefused::MEDIA_STILL_PROCESSING,
                MediaImageStatus::Rejected => RoundRefused::MEDIA_REJECTED,
                MediaImageStatus::Ready => null,
            };
            if (null !== $refusal) {
                return $refusal;
            }
        }

        return null;
    }
}
