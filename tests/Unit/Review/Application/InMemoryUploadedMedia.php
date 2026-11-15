<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Like UploaderMediaStates: the state of each asked picture of the organization that the uploader uploaded, by its id
 * in lowercase, in any case asked; another person's, another organization's, an unknown and a malformed id are left
 * out, and more than MAX_MEDIA_IDS ids or an organization that is not the tenant's are refused before any read.
 * Records every list of ids it reads.
 */
final class InMemoryUploadedMedia implements UploadedMedia
{
    /** @var list<list<string>> */
    public array $askedMediaIds = [];

    /**
     * @param list<array{string, string, string, MediaImageStatus}> $uploads organization, media id in lowercase, uploader, state
     */
    public function __construct(private readonly TenantContext $tenantContext, private readonly array $uploads)
    {
    }

    public function statesOf(OrganizationId $organizationId, UserId $uploader, array $mediaIds): array
    {
        if (\count($mediaIds) > self::MAX_MEDIA_IDS) {
            throw new \InvalidArgumentException(\sprintf('The state is read of at most %d uploads at once.', self::MAX_MEDIA_IDS));
        }
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('An upload state is read under the tenant of its own organization.');
        }
        $this->askedMediaIds[] = $mediaIds;

        $states = [];
        foreach ($mediaIds as $mediaId) {
            foreach ($this->uploads as [$uploadOrganizationId, $uploadId, $uploaderId, $state]) {
                if ($uploadOrganizationId === $organizationId->toString() && $uploadId === strtolower($mediaId) && $uploaderId === $uploader->toString()) {
                    $states[$uploadId] = $state;
                }
            }
        }

        return $states;
    }
}
