<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadSlot;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\StorageQuota;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntents;
use App\Media\Domain\UploadIntentUnusable;
use App\Media\Domain\UploadKey;
use App\Media\Domain\UploadPolicy;
use App\Media\Domain\UploadPurpose;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Uploads straight from the browser to the bucket through a signed PUT URL (ADR-0011), so no file passes through PHP;
 * the contracts are on {@see Uploads} and {@see UploadCompletions}. The media object a completion makes takes its
 * intent's id: an intent is used once, so the id is free, and UploadCompletions finds the picture of a used intent by
 * its primary key.
 */
final readonly class DirectUploads implements Uploads, UploadCompletions
{
    /** The intent lasts a day after the page began it, so the page has been open that long. */
    public const string EXPIRED = 'The page was open too long. Try again.';
    public const string USED_ALREADY = 'This upload has ended. Upload the file again.';
    public const string STORAGE_FULL = "Your company's storage is full. Ask your admin.";
    public const string TOO_MANY_WAITING = 'You have too many uploads waiting. Finish or wait for them, then try again.';

    /**
     * @param int $organizationQuotaBytes what each organization may store, UploadPolicy::UNLIMITED_QUOTA for no limit
     *
     * @throws \InvalidArgumentException when $organizationQuotaBytes is negative
     */
    public function __construct(
        private UploadIntents $uploadIntents,
        private MediaObjects $mediaObjects,
        private StorageQuota $storageQuota,
        private UploadUrlSigner $uploadUrlSigner,
        private UploadProcessingQueue $uploadProcessingQueue,
        private MediaTargetAuthorizers $mediaTargetAuthorizers,
        private Transactions $transactions,
        private TenantContext $tenantContext,
        private int $organizationQuotaBytes,
    ) {
        if ($organizationQuotaBytes < 0) {
            throw new \InvalidArgumentException('The storage quota of an organization is 0 (no limit) or more bytes.');
        }
    }

    public function begin(OrganizationId $organizationId, UserId $userId, UploadTarget $target, string $purpose, string $mime, int $bytes, \DateTimeImmutable $at): UploadSlot
    {
        $uploadPurpose = UploadPurpose::tryFrom($purpose) ?? throw new UploadRefused('Only screenshots can be uploaded here.');
        if (!UploadPolicy::allowsMediaType($mime)) {
            throw new UploadRefused('This file is not a PNG, JPEG or WebP screenshot.');
        }
        if (!UploadPolicy::allowsSize($bytes)) {
            throw new UploadRefused('This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.');
        }
        $this->requireTenant($organizationId);
        $this->requireRoomFor($organizationId, $userId, $bytes, $at);
        $this->requireMayAttach($organizationId, $userId, $target->kind, $target->id);

        $intentId = $this->uploadIntents->nextIdentity();
        $uploadKey = UploadKey::forIntent($organizationId, $intentId);
        $intent = UploadIntent::open($intentId, $organizationId, $userId, $uploadPurpose, $target->kind, $target->id, $bytes, [$mime], $uploadKey->toString(), UploadPolicy::intentExpiryFor($at));
        $this->uploadIntents->add($intent);
        $presignedPut = $this->uploadUrlSigner->forPut($uploadKey, $mime, $bytes);

        return new UploadSlot($intentId->toString(), $presignedPut->url, $presignedPut->headers, $presignedPut->expiresAt);
    }

    public function complete(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): string
    {
        $uploadIntentId = self::intentIdOf($intentId);
        $this->requireTenant($organizationId);

        return $this->transactions->run(function () use ($uploadIntentId, $organizationId, $userId, $at): MediaId {
            try {
                return $this->addMediaObjectFor($this->ownIntent($uploadIntentId, $userId), $organizationId, $userId, $at);
            } catch (UploadIntentUnusable $unusable) {
                throw new UploadRefused($unusable->hasExpired ? self::EXPIRED : self::USED_ALREADY, 0, $unusable);
            }
        })->toString();
    }

    public function targetOf(OrganizationId $organizationId, UserId $userId, string $intentId): ?UploadTarget
    {
        $this->requireTenant($organizationId);
        try {
            $intent = $this->ownIntent(self::intentIdOf($intentId), $userId);
        } catch (UploadIntentNotFound) {
            return null;
        }

        return new UploadTarget($intent->targetKind(), $intent->targetId());
    }

    public function completeInTransaction(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): CompletedUpload
    {
        $uploadIntentId = self::intentIdOf($intentId);
        $this->requireTenant($organizationId);
        $intent = $this->ownIntent($uploadIntentId, $userId);
        if (null === $intent->usedAt()) {
            try {
                return new CompletedUpload($this->addMediaObjectFor($intent, $organizationId, $userId, $at)->toString(), MediaImageStatus::Pending);
            } catch (UploadIntentUnusable $unusable) {
                // Used by another request meanwhile: its picture is the answer.
                if ($unusable->hasExpired) {
                    throw new UploadRefused(self::EXPIRED, 0, $unusable);
                }
            }
        }

        return $this->pictureOf($uploadIntentId);
    }

    /**
     * @throws UploadIntentNotFound when the id is malformed
     */
    private static function intentIdOf(string $intentId): UploadIntentId
    {
        try {
            return new UploadIntentId($intentId);
        } catch (\InvalidArgumentException $malformed) {
            throw new UploadIntentNotFound('The upload intent id is malformed.', 0, $malformed);
        }
    }

    /**
     * @throws UploadIntentNotFound when the organization has no intent with that id that $userId began
     */
    private function ownIntent(UploadIntentId $uploadIntentId, UserId $userId): UploadIntent
    {
        $intent = $this->uploadIntents->byId($uploadIntentId);
        // The tenant filter hides another organization's intent; another person's is answered as a missing one too.
        if (null === $intent || !$intent->userId()->equals($userId)) {
            throw new UploadIntentNotFound('The organization has no upload intent with that id that this person began.');
        }

        return $intent;
    }

    /**
     * @throws UploadTargetDenied   when the stored target now refuses $userId; nothing is written
     * @throws UploadIntentUnusable when the intent is used already or has expired by $at; nothing is written
     */
    private function addMediaObjectFor(UploadIntent $intent, OrganizationId $organizationId, UserId $userId, \DateTimeImmutable $at): MediaId
    {
        $this->requireMayAttach($organizationId, $userId, $intent->targetKind(), $intent->targetId());
        // begin() opens every intent with the one declared type, which the media object then records.
        $allowedMimeTypes = $intent->allowedMimeTypes();
        if (1 !== \count($allowedMimeTypes)) {
            throw new \LogicException('A direct upload intent allows exactly one media type.');
        }

        $this->uploadIntents->recordUse($intent, $at);

        $mediaObject = MediaObject::upload(new MediaId($intent->id()->toString()), $organizationId, $intent->storageKey(), $allowedMimeTypes[0], $intent->maxBytes(), $userId, $at);
        $this->mediaObjects->add($mediaObject);
        $this->uploadProcessingQueue->queue(new ProcessUpload($organizationId->toString(), $mediaObject->id()->toString()));

        return $mediaObject->id();
    }

    /**
     * @throws UploadIntentNotFound when the used intent has no picture of its id, as one completed before pictures took
     *                              their intent's id
     */
    private function pictureOf(UploadIntentId $uploadIntentId): CompletedUpload
    {
        $mediaObject = $this->mediaObjects->byId(new MediaId($uploadIntentId->toString()))
            ?? throw new UploadIntentNotFound('The used upload intent has no picture of its id.');

        // MediaImageStatus mirrors MediaStatus case for case.
        return new CompletedUpload($mediaObject->id()->toString(), MediaImageStatus::from($mediaObject->status()->value));
    }

    private function requireTenant(OrganizationId $organizationId): void
    {
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('An upload runs under the tenant of its own organization.');
        }
    }

    /**
     * The lock lasts until the caller's transaction commits, after the intent is stored, so a second begin of the
     * organization counts it. It is taken without a quota too, as the count of a person's open uploads needs it.
     */
    private function requireRoomFor(OrganizationId $organizationId, UserId $userId, int $bytes, \DateTimeImmutable $at): void
    {
        $this->storageQuota->lock($organizationId);
        if (!UploadPolicy::allowsAnotherOpenUpload($this->uploadIntents->openCountOf($userId, $at))) {
            throw new UploadRefused(self::TOO_MANY_WAITING);
        }
        if (UploadPolicy::UNLIMITED_QUOTA === $this->organizationQuotaBytes) {
            return;
        }

        $usedBytes = $this->storageQuota->bytesInUseAt($organizationId, $at);
        if (!UploadPolicy::fitsQuota($this->organizationQuotaBytes, $usedBytes, $bytes)) {
            throw new UploadRefused(self::STORAGE_FULL);
        }
    }

    private function requireMayAttach(OrganizationId $organizationId, UserId $userId, string $targetKind, string $targetId): void
    {
        if (!$this->mediaTargetAuthorizers->mayAttach($organizationId, $userId, $targetKind, $targetId)) {
            throw new UploadTargetDenied('This person may not add uploads to the target of this upload.');
        }
    }
}
