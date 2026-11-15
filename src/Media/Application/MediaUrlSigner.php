<?php

declare(strict_types=1);

namespace App\Media\Application;

interface MediaUrlSigner
{
    /** How long a signed GET URL works; the browser asks for a new one after that. */
    public const int VALID_FOR_SECONDS = 300;

    /**
     * Signs a URL the browser GETs the stored WebP at $storageKey with, working for VALID_FOR_SECONDS from $signedAt.
     * The storage answers it with `Content-Type: image/webp` and `Content-Disposition: inline`. Reads and writes
     * nothing.
     *
     * @param string $storageKey a variant's key in the bucket, never user input
     *
     * @throws \InvalidArgumentException when $storageKey is empty
     */
    public function forGet(string $storageKey, \DateTimeImmutable $signedAt): string;
}
