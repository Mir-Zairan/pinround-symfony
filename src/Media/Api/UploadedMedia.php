<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Whether the pictures a person uploaded are processed yet, for the form that waits for them (media-pipeline.md,
 * Upload status). Only the uploader may ask: a caller that has not checked who is asking must not pass another id.
 */
interface UploadedMedia
{
    /** The most media ids one call takes. */
    public const int MAX_MEDIA_IDS = 50;

    /**
     * Reads the media objects of $mediaIds in one query. It runs inside the request's transaction, whose tenant is
     * $organizationId.
     *
     * @param list<string> $mediaIds at most MAX_MEDIA_IDS; a malformed or unknown id, one of another organization and
     *                               one that $uploader did not upload are left out of the answer, with no hint which
     *
     * @return array<string, UploadedMediaState> by media id in lowercase
     *
     * @throws \InvalidArgumentException when $mediaIds holds more than MAX_MEDIA_IDS entries, before any read
     * @throws \LogicException           when the request has no tenant or its tenant is another organization, before
     *                                   any read; or when no transaction is open
     */
    public function statesOf(OrganizationId $organizationId, UserId $uploader, array $mediaIds): array;
}
