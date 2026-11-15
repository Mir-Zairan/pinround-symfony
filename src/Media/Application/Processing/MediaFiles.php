<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * The objects of Media's private bucket, as the processing worker needs them. Keys come from UploadKey and
 * MediaVariantKey only. Every failure to reach the storage throws, so it is never mistaken for a missing object.
 */
interface MediaFiles
{
    /**
     * The object's size from its metadata (a HEAD), without reading it.
     *
     * @return int|null in bytes; null when there is no object at $key
     *
     * @throws \RuntimeException when the storage cannot be asked
     */
    public function sizeOf(string $key): ?int;

    /**
     * The object's bytes from its start, at most $maxBytes: no byte past them is read into memory.
     *
     * @param int $maxBytes at least 1
     *
     * @throws \InvalidArgumentException when $maxBytes is below 1
     * @throws \RuntimeException         when there is no object at $key or the storage cannot be read
     */
    public function readUpTo(string $key, int $maxBytes): string;

    /**
     * Stores $bytes at $key, replacing an object there, privately and with $mime as its content type.
     *
     * @throws \RuntimeException when the storage cannot be written
     */
    public function write(string $key, string $bytes, string $mime): void;

    /**
     * Deletes the object at $key; no object there is no failure.
     *
     * @throws \RuntimeException when the storage cannot be written
     */
    public function delete(string $key): void;
}
