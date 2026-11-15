<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use Psr\Clock\ClockInterface;

/**
 * Another clock's time, counting how often it was read; PresignedUploads reads it once for every URL it signs.
 */
final class CountingClock implements ClockInterface
{
    public int $readCount = 0;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        ++$this->readCount;

        return $this->clock->now();
    }
}
