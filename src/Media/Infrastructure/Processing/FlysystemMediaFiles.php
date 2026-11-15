<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Processing;

use App\Media\Application\Processing\MediaFiles;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;

/**
 * MediaFiles on Media's bucket through Flysystem (media.object_storage, services.yaml). Flysystem's exceptions are
 * RuntimeExceptions, which the port's callers treat as a storage they could not reach.
 */
final readonly class FlysystemMediaFiles implements MediaFiles
{
    public function __construct(private FilesystemOperator $objectStorage)
    {
    }

    public function sizeOf(string $key): ?int
    {
        try {
            return $this->objectStorage->fileSize($key);
        } catch (UnableToRetrieveMetadata $failure) {
            // The S3 adapter fails the same way for a missing object and an unreachable storage; asking whether it
            // exists tells them apart, and throws itself when the storage cannot be reached.
            if (!$this->objectStorage->fileExists($key)) {
                return null;
            }

            throw $failure;
        }
    }

    public function readUpTo(string $key, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('A read needs a limit of at least one byte.');
        }

        $stream = $this->objectStorage->readStream($key);
        try {
            $bytes = stream_get_contents($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
        if (false === $bytes) {
            throw new \RuntimeException('The object could not be read from the storage.');
        }

        return $bytes;
    }

    public function write(string $key, string $bytes, string $mime): void
    {
        $this->objectStorage->write($key, $bytes, ['ContentType' => $mime, 'visibility' => Visibility::PRIVATE]);
    }

    public function delete(string $key): void
    {
        $this->objectStorage->delete($key);
    }
}
