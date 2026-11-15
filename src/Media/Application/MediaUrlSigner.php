<?php

declare(strict_types=1);

namespace App\Media\Application;

interface MediaUrlSigner
{
    /** How long a signed GET URL works, counted from the start of its signing window. */
    public const int VALID_FOR_SECONDS = 300;

    /** Signing times are rounded down to this window, so renders inside one window get the same URL (and a cacheable image). */
    public const int SIGNING_WINDOW_SECONDS = 60;

    /** The least a URL still works for, however late in its window it is handed out. */
    public const int MIN_VALID_FOR_SECONDS = self::VALID_FOR_SECONDS - self::SIGNING_WINDOW_SECONDS;

    /**
     * Signs a URL the browser GETs the stored WebP at $storageKey with. $signedAt is rounded down to the start of its
     * SIGNING_WINDOW_SECONDS window and the URL works VALID_FOR_SECONDS from there, so at least MIN_VALID_FOR_SECONDS
     * after $signedAt; the same key in the same window gives the same URL. The storage answers it with
     * `Content-Type: image/webp`, `Content-Disposition: inline` and `Cache-Control: private, max-age=MIN_VALID_FOR_SECONDS,
     * immutable`. Reads and writes nothing.
     *
     * @param string $storageKey a variant's key in the bucket, never user input
     *
     * @throws \InvalidArgumentException when $storageKey is empty
     */
    public function forGet(string $storageKey, \DateTimeImmutable $signedAt): string;
}
