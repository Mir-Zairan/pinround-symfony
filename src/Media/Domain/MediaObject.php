<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * One uploaded picture. It starts pending at its upload key; the processing worker then marks it ready, with the hash,
 * size and storage keys of its re-encoded variants, or rejects it with a reason. Either ends processing for good.
 */
final class MediaObject implements TenantOwned
{
    public const int MAX_REJECT_REASON_LENGTH = 200;

    // The lowercase hex form hash('sha256', …) returns; \z, because $ would also accept a trailing newline.
    private const string SHA256_HEX = '/^[0-9a-f]{64}\z/';

    // Kept as strings and wrapped on the way out, like Ping: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $uploadedBy;
    private MediaStatus $status = MediaStatus::Pending;
    private ?string $rejectReason = null;
    private ?string $sha256 = null;
    private ?int $width = null;
    private ?int $height = null;
    /** @var array<string, string>|null */
    private ?array $variants = null;

    private function __construct(MediaId $id, OrganizationId $organizationId, private readonly string $storageKey, private readonly string $mime, private readonly int $bytes, UserId $uploadedBy, private readonly \DateTimeImmutable $createdAt)
    {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->uploadedBy = $uploadedBy->toString();
    }

    /**
     * A pending media object for a file uploaded to $storageKey.
     *
     * @param string $mime  the media type the upload was accepted as
     * @param int    $bytes the size the upload was accepted with, at least 1
     *
     * @throws \InvalidArgumentException when $storageKey or $mime is empty or $bytes is below 1
     */
    public static function upload(MediaId $id, OrganizationId $organizationId, string $storageKey, string $mime, int $bytes, UserId $uploadedBy, \DateTimeImmutable $createdAt): self
    {
        if ('' === $storageKey) {
            throw new \InvalidArgumentException('A media object needs a storage key.');
        }
        if ('' === $mime) {
            throw new \InvalidArgumentException('A media object needs a media type.');
        }
        if ($bytes < 1) {
            throw new \InvalidArgumentException('A media object needs a size of at least one byte.');
        }

        return new self($id, $organizationId, $storageKey, $mime, $bytes, $uploadedBy, $createdAt);
    }

    /**
     * @param string                $sha256   the hash of the re-encoded view, 64 lowercase hex digits
     * @param int                   $width    of the re-encoded view in pixels, at least 1
     * @param int                   $height   of the re-encoded view in pixels, at least 1
     * @param array<string, string> $variants the storage key of each variant by its name, at least one, no part empty
     *
     * @throws \LogicException           when the media object is not pending; nothing changes then
     * @throws \InvalidArgumentException when a value is out of bounds; nothing changes then
     */
    public function markReady(string $sha256, int $width, int $height, array $variants): void
    {
        $this->requirePending();
        if (1 !== preg_match(self::SHA256_HEX, $sha256)) {
            throw new \InvalidArgumentException('A media hash must be 64 lowercase hex digits.');
        }
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('A media object needs a width and a height of at least one pixel.');
        }
        if ([] === $variants || \in_array('', array_keys($variants), true) || \in_array('', $variants, true)) {
            throw new \InvalidArgumentException('A ready media object needs at least one variant, each with a name and a storage key.');
        }

        $this->status = MediaStatus::Ready;
        $this->sha256 = $sha256;
        $this->width = $width;
        $this->height = $height;
        $this->variants = $variants;
    }

    /**
     * @param string $reason 1 to MAX_REJECT_REASON_LENGTH characters after trimming
     *
     * @throws \LogicException           when the media object is not pending; nothing changes then
     * @throws \InvalidArgumentException when the trimmed reason is empty or too long; nothing changes then
     */
    public function reject(string $reason): void
    {
        $this->requirePending();
        $trimmedReason = trim($reason);
        $length = mb_strlen($trimmedReason);
        if (0 === $length || $length > self::MAX_REJECT_REASON_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A rejection needs a reason of 1 to %d characters.', self::MAX_REJECT_REASON_LENGTH));
        }

        $this->status = MediaStatus::Rejected;
        $this->rejectReason = $trimmedReason;
    }

    public function id(): MediaId
    {
        return new MediaId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function uploadedBy(): UserId
    {
        return new UserId($this->uploadedBy);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function status(): MediaStatus
    {
        return $this->status;
    }

    /**
     * Why processing refused the upload; null unless rejected.
     */
    public function rejectReason(): ?string
    {
        return $this->rejectReason;
    }

    /**
     * The hash of the re-encoded view, the key of deduplication in an organization; null unless ready.
     */
    public function sha256(): ?string
    {
        return $this->sha256;
    }

    /**
     * Null unless ready.
     */
    public function width(): ?int
    {
        return $this->width;
    }

    /**
     * Null unless ready.
     */
    public function height(): ?int
    {
        return $this->height;
    }

    /**
     * @return array<string, string>|null the storage key of each variant by its name; null unless ready
     */
    public function variants(): ?array
    {
        return $this->variants;
    }

    private function requirePending(): void
    {
        if (MediaStatus::Pending !== $this->status) {
            throw new \LogicException(\sprintf('The media object is %s already.', $this->status->value));
        }
    }
}
