<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Short-lived signed GET URLs of stored pictures (media-pipeline.md, Serving images): the browser reads the bytes from
 * the private bucket, never through PHP. Media knows no tickets or rounds, so the caller has decided with its own voter,
 * before it asks, that the viewer may see what the pictures belong to; this only signs.
 */
interface SignedUrls
{
    /** The most media ids one call takes, the 50 screenshots of a round several times over. */
    public const int MAX_MEDIA_IDS = 200;

    /**
     * Reads every media object of $mediaIds in one query and signs, for each ready one, a GET URL of its $variant that
     * works for 5 minutes from the start of $at's minute (so at least 4 minutes after $at); calls in the same minute
     * give the same URL. The storage answers with `Content-Type: image/webp`, `Content-Disposition: inline` and
     * `Cache-Control: private, max-age=240, immutable`. Signing is local: no request goes to the storage. It runs
     * inside the request's transaction, whose tenant is $organizationId.
     *
     * @param list<string> $mediaIds at most MAX_MEDIA_IDS; a malformed, unknown or another organization's id is left
     *                               out of the answer, and an id given twice is answered once
     * @param string       $variant  "view" (at most 1920 px wide) or "thumb" (at most 480 px wide); a ready picture
     *                               stored with no thumb answers its view for "thumb"
     *
     * @return array<string, MediaImage> by media id in lowercase, in the order of $mediaIds; a ready one has the width
     *                                   and height of the variant its URL shows, the thumb's worked out from the
     *                                   view's (within a pixel of the file's)
     *
     * @throws \InvalidArgumentException when $variant is unknown or $mediaIds holds more than MAX_MEDIA_IDS entries,
     *                                   before any read
     * @throws \LogicException           when the request has no tenant or its tenant is another organization, before
     *                                   any read; when no transaction is open; or when a ready media object has no
     *                                   view stored
     */
    public function forVariants(OrganizationId $organizationId, array $mediaIds, string $variant, \DateTimeImmutable $at): array;
}
