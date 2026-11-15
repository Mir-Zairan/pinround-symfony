<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageQuota;
use App\Media\Domain\UploadIntent;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;

/**
 * StorageQuota as DoctrineStorageQuota behaves on the real tables: it needs an open transaction, counts the pending and
 * ready media objects and the intents neither used nor expired of the organization asked about, and sees no rows of
 * another organization than the tenant, as row-level security hides them. A lock only writes to the PortCallLog, as
 * one PHP process has no second begin to make wait; so does each read.
 */
final readonly class InMemoryStorageQuota implements StorageQuota
{
    public function __construct(
        private InMemoryTransactions $transactions,
        private TenantContext $tenantContext,
        private InMemoryMediaObjects $mediaObjects,
        private InMemoryUploadIntents $uploadIntents,
        private PortCallLog $portCallLog,
    ) {
    }

    public function lock(OrganizationId $organizationId): void
    {
        $this->requireTransaction('Locking the storage quota needs an open transaction.');
        $this->portCallLog->record('lock '.$organizationId->toString());
    }

    public function bytesInUseAt(OrganizationId $organizationId, \DateTimeImmutable $at): int
    {
        $this->requireTransaction('Reading the storage in use needs an open transaction.');
        $this->portCallLog->record('bytesInUseAt '.$organizationId->toString());

        if (!$this->tenantContext->require()->equals($organizationId)) {
            return 0;
        }
        $objectsTakingSpace = array_filter(
            $this->mediaObjects->storedRows(),
            static fn (MediaObject $mediaObject): bool => $mediaObject->organizationId()->equals($organizationId) && \in_array($mediaObject->status(), [MediaStatus::Pending, MediaStatus::Ready], true),
        );
        $openIntents = array_filter(
            $this->uploadIntents->storedRows(),
            static fn (UploadIntent $intent): bool => $intent->organizationId()->equals($organizationId) && null === $intent->usedAt() && $intent->expiresAt() > $at,
        );

        return array_sum(array_map(static fn (MediaObject $mediaObject): int => $mediaObject->bytes(), $objectsTakingSpace))
            + array_sum(array_map(static fn (UploadIntent $intent): int => $intent->maxBytes(), $openIntents));
    }

    private function requireTransaction(string $refusal): void
    {
        if (!$this->transactions->isOpen()) {
            throw new \LogicException($refusal);
        }
    }
}
