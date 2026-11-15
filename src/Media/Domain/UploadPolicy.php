<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * What a direct upload may be (ADR-0011), how long its intent lasts (media-pipeline.md, Failure handling), how many a
 * person may hold open and whether it fits the organization's storage quota (media-pipeline.md, Quotas).
 */
final class UploadPolicy
{
    /** The picture types ADR-0011 accepts, exactly, without parameters; the processing worker checks the bytes again. */
    public const array MEDIA_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** 15 MB, the largest original ADR-0011 accepts. */
    public const int MAX_BYTES = 15 * 1024 * 1024;

    /** 40 megapixels, the largest picture ADR-0011 lets the processing worker decode. */
    public const int MAX_PIXELS = 40_000_000;

    /** The longest side ADR-0011 lets the processing worker decode, in pixels. */
    public const int MAX_SIDE_PIXELS = 16_384;

    /** A quota of 0 bytes is no quota, for a self-hosted install whose operator sets none. */
    public const int UNLIMITED_QUOTA = 0;

    /**
     * Each open intent holds its declared bytes of the quota for a day, so this bounds what one person can hold back
     * from the rest of the organization: 20 × 15 MB.
     */
    public const int MAX_OPEN_UPLOADS_PER_USER = 20;

    /** An interrupted upload's intent lasts a day, as does its bucket object. */
    private const string INTENT_LIFETIME = 'P1D';

    private function __construct()
    {
    }

    /**
     * @param string $mime compared exactly, so parameters or another letter case are refused
     */
    public static function allowsMediaType(string $mime): bool
    {
        return \in_array($mime, self::MEDIA_TYPES, true);
    }

    public static function allowsSize(int $bytes): bool
    {
        return $bytes >= 1 && $bytes <= self::MAX_BYTES;
    }

    /**
     * Whether a picture whose header gives $width × $height pixels may be decoded; read before decoding, so an image
     * bomb is refused before it takes any memory.
     */
    public static function allowsDimensions(int $width, int $height): bool
    {
        // The sides first, so the product of two huge header values never overflows into a float.
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE_PIXELS || $height > self::MAX_SIDE_PIXELS) {
            return false;
        }

        return $width * $height <= self::MAX_PIXELS;
    }

    /**
     * Whether a person who holds $openUploads intents neither used nor expired may begin one more.
     */
    public static function allowsAnotherOpenUpload(int $openUploads): bool
    {
        return $openUploads < self::MAX_OPEN_UPLOADS_PER_USER;
    }

    /**
     * Whether $declaredBytes more fit in $quotaBytes when $usedBytes are taken; a quota is reached, not passed, when
     * used and declared add up to it exactly.
     *
     * @param int $quotaBytes    UNLIMITED_QUOTA or a positive number of bytes
     * @param int $usedBytes     what the organization's stored files and open uploads take, 0 or more
     * @param int $declaredBytes the size of the new upload
     *
     * @throws \InvalidArgumentException when $quotaBytes or $usedBytes is negative
     */
    public static function fitsQuota(int $quotaBytes, int $usedBytes, int $declaredBytes): bool
    {
        if ($quotaBytes < 0 || $usedBytes < 0) {
            throw new \InvalidArgumentException('A storage quota and the bytes used of it are 0 or more.');
        }
        if (self::UNLIMITED_QUOTA === $quotaBytes) {
            return true;
        }

        // Subtracting, not adding, so a huge used total cannot overflow into a float.
        return $declaredBytes <= $quotaBytes - $usedBytes;
    }

    /**
     * The first moment an intent begun at $begunAt can no longer be used.
     */
    public static function intentExpiryFor(\DateTimeImmutable $begunAt): \DateTimeImmutable
    {
        return $begunAt->add(new \DateInterval(self::INTENT_LIFETIME));
    }
}
