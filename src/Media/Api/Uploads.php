<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Direct uploads of screenshots (media-pipeline.md, Upload flow): begin() hands out a slot the browser PUTs one file
 * into, complete() turns that file into a pending media object and queues its processing. Whether the person may
 * upload for the target is the caller's MediaPermission::UPLOAD check, made before begin(); both calls also ask the
 * MediaTargetAuthorizer of the target's kind themselves, complete() for the target stored at begin(). Both calls
 * must run under the tenant $organizationId: a caller with no tenant, or another one, gets a LogicException. A
 * logged-in actor who is not $userId, or an authorizer refusal, gives UploadTargetDenied (so a console command,
 * message handler or MCP call without a token is refused).
 */
interface Uploads
{
    /**
     * Stores an upload intent of $userId for $target that expires a day after $at and signs a PUT URL for it that pins
     * $mime and exactly $bytes. It writes under row-level security, so it needs an open transaction whose tenant is
     * $organizationId; the intent is kept only if that transaction commits. A refused upload stores and signs nothing.
     * The organization's begins wait for each other until that transaction ends, so two at once cannot both take the
     * last free bytes or a person's last open upload.
     *
     * @param string $purpose one of the upload purposes, today only "screenshot"
     * @param string $mime    the declared media type: image/png, image/jpeg or image/webp, exactly
     * @param int    $bytes   the declared size, 1 byte to 15 MB
     *
     * @throws UploadRefused             when the purpose is unknown, the type is not allowed or the size is out of
     *                                   bounds, in that order, before any write; then, under the right tenant, when
     *                                   $userId holds 20 unused, unexpired intents already ("You have too many uploads
     *                                   waiting. Finish or wait for them, then try again."), and then, unless the
     *                                   quota is unlimited, when $bytes do not fit the organization's quota beside its
     *                                   pending and ready media objects and its unused, unexpired intents ("Your
     *                                   company's storage is full. Ask your admin.")
     * @throws UploadTargetDenied        when the logged-in actor is not $userId, the MediaTargetAuthorizer of $target's
     *                                   kind refuses $userId, or no authorizer decides that kind
     * @throws \InvalidArgumentException when $target's kind is longer than 40 characters or its id longer than 100, which
     *                                   the authorizer refuses first for any target it knows
     * @throws \LogicException           when no transaction is open, or the tenant context is missing or is not
     *                                   $organizationId
     */
    public function begin(OrganizationId $organizationId, UserId $userId, UploadTarget $target, string $purpose, string $mime, int $bytes, \DateTimeImmutable $at): UploadSlot;

    /**
     * Checks the target the intent was begun for again, then marks the intent used at $at, adds a pending media object
     * for its file created at $at and writes its processing to the outbox, all in one transaction of its own: they
     * commit together or not at all. The intent is used once only, even by two calls at once, and expires by $at.
     *
     * @return string the id of the new media object, a UUIDv7
     *
     * @throws UploadIntentNotFound when $intentId is malformed, or the organization has no intent with that id that
     *                              $userId began; nothing changes then
     * @throws UploadTargetDenied   when the MediaTargetAuthorizer of the stored target's kind now refuses $userId, or no
     *                              authorizer decides that kind; nothing changes then
     * @throws UploadRefused        when the intent is used already ("This upload has ended. Upload the file again.") or
     *                              has expired by $at ("The page was open too long. Try again."); nothing changes then
     * @throws \LogicException      when the request has no tenant, its tenant is another organization or a transaction
     *                              is open; nothing changes then
     */
    public function complete(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): string;
}
