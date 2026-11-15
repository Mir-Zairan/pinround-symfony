<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application\Processing;

use App\Media\Application\Processing\MediaFiles;

/**
 * MediaFiles as FlysystemMediaFiles behaves on the bucket: a missing object has no size, reading it throws, deleting it
 * is no failure, and a read never returns more than it was asked for. A test sets $isUnreachable to make every call
 * fail as an unreachable storage does, and $afterRead to change the bucket between two reads.
 */
final class InMemoryMediaFiles implements MediaFiles
{
    public bool $isUnreachable = false;

    /** @var (\Closure(int): void)|null called with the number of reads so far after each readUpTo() */
    public ?\Closure $afterRead = null;

    /** @var array<string, string> */
    private array $objects = [];

    /** @var list<array{string, int}> the key and limit of each readUpTo(), in order */
    private array $reads = [];

    /** @var array<string, string> the mime type each write() was given, by key */
    private array $mimeTypes = [];

    public function put(string $key, string $bytes): void
    {
        $this->objects[$key] = $bytes;
    }

    public function sizeOf(string $key): ?int
    {
        $this->requireReachable();

        return isset($this->objects[$key]) ? \strlen($this->objects[$key]) : null;
    }

    public function readUpTo(string $key, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('A read needs a limit of at least one byte.');
        }
        $this->requireReachable();
        if (!isset($this->objects[$key])) {
            throw new \RuntimeException('The object does not exist.');
        }
        $this->reads[] = [$key, $maxBytes];
        $bytes = substr($this->objects[$key], 0, $maxBytes);
        if (null !== $this->afterRead) {
            ($this->afterRead)(\count($this->reads));
        }

        return $bytes;
    }

    public function write(string $key, string $bytes, string $mime): void
    {
        $this->requireReachable();
        $this->objects[$key] = $bytes;
        $this->mimeTypes[$key] = $mime;
    }

    public function delete(string $key): void
    {
        $this->requireReachable();
        unset($this->objects[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    /**
     * @return list<string> every key in the bucket, sorted
     */
    public function keys(): array
    {
        $keys = array_keys($this->objects);
        sort($keys);

        return $keys;
    }

    public function bytesOf(string $key): string
    {
        return $this->objects[$key] ?? throw new \LogicException('No object at '.$key);
    }

    public function mimeTypeOf(string $key): ?string
    {
        return $this->mimeTypes[$key] ?? null;
    }

    /**
     * @return list<array{string, int}>
     */
    public function reads(): array
    {
        return $this->reads;
    }

    private function requireReachable(): void
    {
        if ($this->isUnreachable) {
            throw new \RuntimeException('The storage cannot be reached.');
        }
    }
}
