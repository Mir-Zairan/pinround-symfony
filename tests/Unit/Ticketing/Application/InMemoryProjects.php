<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use App\Ticketing\Domain\Projects;

final class InMemoryProjects implements Projects
{
    public const string NEXT_ID = '0197a3c0-0000-7000-8000-0000000007a1';

    /** @var array<string, Project> by id */
    public array $saved = [];

    /** @var list<string> the project id of each save() call */
    public array $saveCalls = [];

    /** @var list<string> the project id of each lockById() call */
    public array $lockCalls = [];

    /**
     * @param bool $isKeyTakenOnAdd add() refuses every key, as when another transaction saved the key after byKey()
     */
    public function __construct(private readonly bool $isKeyTakenOnAdd = false, Project ...$projects)
    {
        foreach ($projects as $project) {
            $this->saved[$project->id()->toString()] = $project;
        }
    }

    public function nextIdentity(): ProjectId
    {
        return new ProjectId(self::NEXT_ID);
    }

    public function byId(ProjectId $id): ?Project
    {
        return $this->saved[$id->toString()] ?? null;
    }

    public function lockById(ProjectId $id): ?Project
    {
        $this->lockCalls[] = $id->toString();

        return $this->saved[$id->toString()] ?? null;
    }

    public function byKey(ProjectKey $key): ?Project
    {
        return array_find($this->saved, static fn (Project $project): bool => $project->key()->equals($key));
    }

    public function add(Project $project): void
    {
        if ($this->isKeyTakenOnAdd) {
            throw new ProjectKeyInUse('The unique index refused the key.');
        }
        $this->saved[$project->id()->toString()] = $project;
    }

    public function save(Project $project): void
    {
        if (!isset($this->saved[$project->id()->toString()])) {
            throw new \LogicException('Only a project found or added before is saved.');
        }
        $this->saveCalls[] = $project->id()->toString();
    }
}
