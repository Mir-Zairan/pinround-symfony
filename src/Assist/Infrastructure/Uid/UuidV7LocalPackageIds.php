<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Uid;

use App\Assist\Api\LocalPackageId;
use App\Assist\Application\LocalAi\LocalPackageIds;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class UuidV7LocalPackageIds implements LocalPackageIds
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function next(): LocalPackageId
    {
        return new LocalPackageId(UuidV7::generate($this->clock->now()));
    }
}
