<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\TeamId;

final readonly class TeamSummary
{
    public function __construct(private TeamId $id, private string $name, private string $slug)
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

    /**
     * The team's part of its address, /teams/{slug}: unique within the organization and never changed by a rename.
     */
    public function slug(): string
    {
        return $this->slug;
    }
}
