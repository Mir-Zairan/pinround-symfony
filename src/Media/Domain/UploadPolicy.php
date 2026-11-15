<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * What a direct upload may be (ADR-0011) and how long its intent lasts (media-pipeline.md, Failure handling).
 */
final class UploadPolicy
{
    /** The picture types ADR-0011 accepts, exactly, without parameters; the processing worker checks the bytes again. */
    public const array MEDIA_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** 15 MB, the largest original ADR-0011 accepts. */
    public const int MAX_BYTES = 15 * 1024 * 1024;

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
     * The first moment an intent begun at $begunAt can no longer be used.
     */
    public static function intentExpiryFor(\DateTimeImmutable $begunAt): \DateTimeImmutable
    {
        return $begunAt->add(new \DateInterval(self::INTENT_LIFETIME));
    }
}
