<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Projects;

/**
 * The real repository, but a second request runs between a command's checks and its write: just before save().
 */
final readonly class ProjectsInterruptedBeforeSave implements Projects
{
    /**
     * @param \Closure(): void $secondRequest
     */
    public function __construct(private Projects $projects, private \Closure $secondRequest)
    {
    }

    public function nextIdentity(): ProjectId
    {
        return $this->projects->nextIdentity();
    }

    public function byId(ProjectId $id): ?Project
    {
        return $this->projects->byId($id);
    }

    public function lockById(ProjectId $id): ?Project
    {
        return $this->projects->lockById($id);
    }

    public function byKey(ProjectKey $key): ?Project
    {
        return $this->projects->byKey($key);
    }

    public function add(Project $project): void
    {
        $this->projects->add($project);
    }

    public function save(Project $project): void
    {
        ($this->secondRequest)();
        $this->projects->save($project);
    }
}
