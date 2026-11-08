<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\TeamId;

final readonly class TeamSummary
{
    public function __construct(private TeamId $id, private string $name)
    {
    }

    public function id(): TeamId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
