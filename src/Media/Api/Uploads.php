<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Direct uploads of screenshots (media-pipeline.md, Upload flow): begin() hands out a slot the browser PUTs one file
 * into, complete() turns that file into a pending media object and queues its processing. Whether the person may
 * upload for the target is the caller's voter check, made before either call.
 */
interface Uploads
{
    /**
     * Stores an upload intent of $userId that expires a day from now and signs a PUT URL for it that pins $mime and
     * exactly $bytes. It writes under row-level security, so it needs an open transaction whose tenant is
     * $organizationId; the intent is kept only if that transaction commits. A refused upload stores and signs nothing.
     *
     * @param string $purpose one of the upload purposes, today only "screenshot"
     * @param string $mime    the declared media type: image/png, image/jpeg or image/webp, exactly
     * @param int    $bytes   the declared size, 1 byte to 15 MB
     *
     * @throws UploadRefused   when the purpose is unknown, the type is not allowed or the size is out of bounds, in
     *                         that order, before any write
     * @throws \LogicException when no transaction is open or its tenant is another organization
     */
    public function begin(OrganizationId $organizationId, UserId $userId, string $purpose, string $mime, int $bytes): UploadSlot;

    /**
     * Marks the intent used and adds a pending media object for its file, in a transaction of its own, then queues the
     * file's processing once that transaction has committed. The intent is used once only, even by two calls at once.
     *
     * @return string the id of the new media object, a UUIDv7
     *
     * @throws UploadIntentNotFound when $intentId is malformed, or the organization has no intent with that id that
     *                              $userId began; nothing changes then
     * @throws UploadRefused        when the intent is used already or has expired; nothing changes then
     * @throws \LogicException      when the request has no tenant, its tenant is another organization or a transaction
     *                              is open
     */
    public function complete(OrganizationId $organizationId, UserId $userId, string $intentId): string;
}
