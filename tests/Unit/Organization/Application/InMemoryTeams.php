<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Team;
use App\Organization\Domain\Teams;
use App\SharedKernel\Domain\TeamId;

/**
 * Teams in memory. Nothing locks; the calls are logged in order.
 */
final class InMemoryTeams implements Teams
{
    public const string NEXT_ID = '0197a3c0-0000-7000-8000-0000000005c9';

    /** @var array<string, Team> */
    private array $teams = [];
    /** @var list<string> */
    public array $calls = [];

    public function __construct(Team ...$teams)
    {
        foreach ($teams as $team) {
            $this->teams[$team->id()->toString()] = $team;
        }
    }

    public function nextIdentity(): TeamId
    {
        return new TeamId(self::NEXT_ID);
    }

    public function byId(TeamId $id): ?Team
    {
        $this->calls[] = 'byId';

        return $this->teams[$id->toString()] ?? null;
    }

    public function lockById(TeamId $id): ?Team
    {
        $this->calls[] = 'lockById';

        return $this->teams[$id->toString()] ?? null;
    }

    public function add(Team $team): void
    {
        $this->calls[] = 'add';
        $this->teams[$team->id()->toString()] = $team;
    }

    public function save(Team $team): void
    {
        $this->calls[] = 'save';
    }
}
