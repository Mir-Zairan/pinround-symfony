<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\Uploads;
use App\Media\Api\UploadSlot;
use App\Media\Application\ProcessUpload;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntents;
use App\Media\Domain\UploadIntentUnusable;
use App\Media\Domain\UploadPurpose;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\Media\Infrastructure\Storage\UploadKey;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Uploads straight from the browser to the bucket through a presigned PUT (ADR-0011), so no file passes through PHP.
 */
final readonly class DirectUploads implements Uploads
{
    /** An interrupted upload's intent lasts a day (media-pipeline.md, Failure handling), as does its bucket object. */
    private const string INTENT_LIFETIME = 'P1D';

    public function __construct(
        private UploadIntents $uploadIntents,
        private MediaObjects $mediaObjects,
        private PresignedUploads $presignedUploads,
        private ClockInterface $clock,
        private Transactions $transactions,
        private TenantContext $tenantContext,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
    ) {
    }

    public function begin(OrganizationId $organizationId, UserId $userId, string $purpose, string $mime, int $bytes): UploadSlot
    {
        $uploadPurpose = UploadPurpose::tryFrom($purpose) ?? throw new UploadRefused('Only screenshots can be uploaded here.');
        if (!\in_array($mime, PresignedUploads::MEDIA_TYPES, true)) {
            throw new UploadRefused('This file is not a PNG, JPEG or WebP screenshot.');
        }
        if ($bytes < 1 || $bytes > PresignedUploads::MAX_BYTES) {
            throw new UploadRefused('This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.');
        }
        $this->requireTenant($organizationId);

        $intentId = $this->uploadIntents->nextIdentity();
        $uploadKey = UploadKey::forIntent($organizationId, $intentId);
        $expiresAt = $this->clock->now()->add(new \DateInterval(self::INTENT_LIFETIME));
        $this->uploadIntents->add(UploadIntent::open($intentId, $organizationId, $userId, $uploadPurpose, $bytes, [$mime], $uploadKey->toString(), $expiresAt));
        $presignedPut = $this->presignedUploads->forPut($uploadKey, $mime, $bytes);

        return new UploadSlot($intentId->toString(), $presignedPut->url, $presignedPut->headers, $presignedPut->expiresAt);
    }

    /**
     * The message goes out only after the commit, so a fast worker never looks for a row it cannot see yet. Should the
     * send fail then, the exception reaches the caller and the media object stays pending.
     */
    public function complete(OrganizationId $organizationId, UserId $userId, string $intentId): string
    {
        try {
            $uploadIntentId = new UploadIntentId($intentId);
        } catch (\InvalidArgumentException $malformed) {
            throw new UploadIntentNotFound('The upload intent id is malformed.', 0, $malformed);
        }
        $this->requireTenant($organizationId);

        $mediaId = $this->transactions->run(fn (): MediaId => $this->addMediaObjectFor($uploadIntentId, $organizationId, $userId));
        $this->commandBus->dispatch(new ProcessUpload($organizationId->toString(), $mediaId->toString()));

        return $mediaId->toString();
    }

    private function addMediaObjectFor(UploadIntentId $uploadIntentId, OrganizationId $organizationId, UserId $userId): MediaId
    {
        $intent = $this->uploadIntents->byId($uploadIntentId);
        // The tenant filter hides another organization's intent; another person's is answered as a missing one too.
        if (null === $intent || !$intent->userId()->equals($userId)) {
            throw new UploadIntentNotFound('The organization has no upload intent with that id that this person began.');
        }
        // begin() opens every intent with the one declared type, which the media object then records.
        $allowedMimeTypes = $intent->allowedMimeTypes();
        if (1 !== \count($allowedMimeTypes)) {
            throw new \LogicException('A direct upload intent allows exactly one media type.');
        }

        try {
            $this->uploadIntents->recordUse($intent);
        } catch (UploadIntentUnusable $unusable) {
            throw new UploadRefused('This upload has ended. Upload the file again.', 0, $unusable);
        }

        $mediaObject = MediaObject::upload($this->mediaObjects->nextIdentity(), $organizationId, $intent->storageKey(), $allowedMimeTypes[0], $intent->maxBytes(), $userId, $this->clock->now());
        $this->mediaObjects->add($mediaObject);

        return $mediaObject->id();
    }

    private function requireTenant(OrganizationId $organizationId): void
    {
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('An upload runs under the tenant of its own organization.');
        }
    }
}
