<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A slot one person may upload one file into, under a fixed storage key, until it expires (media-pipeline.md, Upload
 * flow). It is used once: completing the upload marks it used.
 */
final class UploadIntent implements TenantOwned
{
    // Kept as strings and wrapped on the way out, like Ping: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $userId;
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * @param list<string> $allowedMimeTypes
     */
    private function __construct(UploadIntentId $id, OrganizationId $organizationId, UserId $userId, private readonly UploadPurpose $purpose, private readonly int $maxBytes, private readonly array $allowedMimeTypes, private readonly string $storageKey, private readonly \DateTimeImmutable $expiresAt)
    {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->userId = $userId->toString();
    }

    /**
     * @param int          $maxBytes         the largest file the slot takes, at least 1
     * @param list<string> $allowedMimeTypes the exact media types the slot takes, at least one
     * @param string       $storageKey       the bucket key the file goes to
     *
     * @throws \InvalidArgumentException when $maxBytes is below 1, $allowedMimeTypes is empty or holds an empty type,
     *                                   or $storageKey is empty
     */
    public static function open(UploadIntentId $id, OrganizationId $organizationId, UserId $userId, UploadPurpose $purpose, int $maxBytes, array $allowedMimeTypes, string $storageKey, \DateTimeImmutable $expiresAt): self
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('An upload intent needs a maximum size of at least one byte.');
        }
        if ([] === $allowedMimeTypes || \in_array('', $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('An upload intent needs at least one allowed media type.');
        }
        if ('' === $storageKey) {
            throw new \InvalidArgumentException('An upload intent needs a storage key.');
        }

        return new self($id, $organizationId, $userId, $purpose, $maxBytes, array_values(array_unique($allowedMimeTypes)), $storageKey, $expiresAt);
    }

    /**
     * Checks a file's size and media type against the slot, as declared by the person or as found in storage.
     *
     * @param string $mime compared exactly, so parameters or another letter case are refused
     *
     * @throws \InvalidArgumentException when $bytes is below 1 or above the maximum, or $mime is not allowed
     */
    public function checkFile(int $bytes, string $mime): void
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('An upload needs a size of at least one byte.');
        }
        if ($bytes > $this->maxBytes) {
            throw new \InvalidArgumentException(\sprintf('This upload may be at most %d bytes.', $this->maxBytes));
        }
        if (!\in_array($mime, $this->allowedMimeTypes, true)) {
            throw new \InvalidArgumentException(\sprintf('This upload must be one of: %s.', implode(', ', $this->allowedMimeTypes)));
        }
    }

    /**
     * Marks the slot used at $at. Only in memory: UploadIntents::recordUse() writes it so that a second use at the same
     * time fails too.
     *
     * @throws UploadIntentUnusable when the slot is used already or has expired by $at; nothing changes then
     */
    public function markUsed(\DateTimeImmutable $at): void
    {
        if (null !== $this->usedAt) {
            throw new UploadIntentUnusable('The upload intent is used already.');
        }
        if ($at >= $this->expiresAt) {
            throw new UploadIntentUnusable('The upload intent has expired.');
        }

        $this->usedAt = $at;
    }

    public function id(): UploadIntentId
    {
        return new UploadIntentId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function userId(): UserId
    {
        return new UserId($this->userId);
    }

    public function purpose(): UploadPurpose
    {
        return $this->purpose;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * The first moment the slot can no longer be used.
     */
    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }
}
