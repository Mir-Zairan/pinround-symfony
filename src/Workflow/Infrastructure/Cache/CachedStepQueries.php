<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Cache;

use App\Organization\Api\SettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\StepQueries;
use App\Workflow\Infrastructure\Doctrine\DbalStepQueries;

/**
 * DbalStepQueries through WorkflowCache. groupOf() is not cached: it is one query by primary key.
 */
final readonly class CachedStepQueries implements StepQueries
{
    public function __construct(
        private DbalStepQueries $stepQueries,
        private WorkflowCache $workflowCache,
        private SettingsQueries $settingsQueries,
        private TenantContext $tenantContext,
    ) {
    }

    public function stepsFor(TeamId $teamId): array
    {
        return $this->workflowCache->remember($teamId, 'steps', fn (): array => $this->stepQueries->stepsFor($teamId));
    }

    public function moveTargetsFor(string $teamId): array
    {
        $team = new TeamId($teamId);
        // The step policy is in the key, so a policy change needs no invalidation.
        $stepPolicy = $this->settingsQueries->stepPolicyOf($this->tenantContext->require());

        return $this->workflowCache->remember($team, 'move-targets.'.$stepPolicy->value, fn (): array => $this->stepQueries->moveTargetsFor($teamId));
    }

    public function groupOf(string $stepId): ?string
    {
        return $this->stepQueries->groupOf($stepId);
    }
}
