<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * A project as a picker offers it, with the team that owns it, so the picker can group projects by team and tell
 * which team a new ticket goes to.
 */
final readonly class TeamProjectChoice
{
    /**
     * @param string $id  the project's uuid
     * @param string $key the ticket key prefix, for example SHOP
     */
    public function __construct(
        private string $id,
        private string $key,
        private string $name,
        private TeamId $teamId,
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

    public function teamId(): TeamId
    {
        return $this->teamId;
    }
}
