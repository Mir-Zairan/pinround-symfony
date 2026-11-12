<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * A project as a picker offers it: what a person needs to recognise it, with no ticket counts.
 */
final readonly class ProjectChoice
{
    /**
     * @param string      $id     the project's uuid
     * @param string      $key    the ticket key prefix, for example SHOP
     * @param TeamId|null $teamId the team that owns it; null when no team does, or when the read did not ask for it
     */
    public function __construct(
        private string $id,
        private string $key,
        private string $name,
        private ?TeamId $teamId = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function teamId(): ?TeamId
    {
        return $this->teamId;
    }
}
