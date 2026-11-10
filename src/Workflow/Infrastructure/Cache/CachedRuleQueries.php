<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Cache;

use App\Organization\Api\SettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Infrastructure\Doctrine\DbalRuleQueries;

/**
 * DbalRuleQueries through WorkflowCache.
 */
final readonly class CachedRuleQueries implements RuleQueries
{
    public function __construct(
        private DbalRuleQueries $ruleQueries,
        private WorkflowCache $workflowCache,
        private SettingsQueries $settingsQueries,
        private TenantContext $tenantContext,
    ) {
    }

    public function rulesFor(string $teamId): RuleSetView
    {
        $team = new TeamId($teamId);
        // The step policy picks the team's rule set or the organization's, so it is in the key: a policy change needs
        // no invalidation.
        $stepPolicy = $this->settingsQueries->stepPolicyOf($this->tenantContext->require());

        return $this->workflowCache->remember($team, 'rules.'.$stepPolicy->value, fn (): RuleSetView => $this->ruleQueries->rulesFor($teamId));
    }
}
