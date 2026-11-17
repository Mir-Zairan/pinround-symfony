<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Uid;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Application\Intake\IntakeCheckIds;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class UuidV7IntakeCheckIds implements IntakeCheckIds
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function next(): IntakeCheckId
    {
        return new IntakeCheckId(UuidV7::generate($this->clock->now()));
    }
}
