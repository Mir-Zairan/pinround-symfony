<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\UploadKey;
use App\Media\Domain\UploadPolicy;

interface UploadUrlSigner
{
    /**
     * Signs a URL the browser PUTs one file to at $key, pinning $mime and exactly $bytes, so the storage refuses another
     * type or size. Writes nothing.
     *
     * @param string $mime  one of UploadPolicy::MEDIA_TYPES, exactly
     * @param int    $bytes 1 to UploadPolicy::MAX_BYTES
     *
     * @throws \InvalidArgumentException when $mime or $bytes is outside {@see UploadPolicy}
     */
    public function forPut(UploadKey $key, string $mime, int $bytes): PresignedPut;
}
