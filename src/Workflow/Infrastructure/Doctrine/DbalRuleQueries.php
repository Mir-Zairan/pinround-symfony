<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\RuleSets;

final readonly class DbalRuleQueries implements RuleQueries
{
    public function __construct(
        private RuleSets $ruleSets,
        private SettingsQueries $settingsQueries,
        private TenantContext $tenantContext,
    ) {
    }

    public function rulesFor(TeamId $teamId): RuleSetView
    {
        return $this->rulesUnder($teamId, $this->settingsQueries->stepPolicyOf($this->tenantContext->require()));
    }

    /**
     * rulesFor with the step policy already read, so a caller that keys a cache on the policy stores what it keyed.
     */
    public function rulesUnder(TeamId $teamId, StepPolicy $stepPolicy): RuleSetView
    {
        $organizationId = $this->tenantContext->require();

        $teamRules = StepPolicy::Team === $stepPolicy
            ? $this->ruleSets->forTeam($organizationId, $teamId)
            : null;
        $rules = $teamRules ?? $this->ruleSets->forOrganization($organizationId) ?? RuleSet::defaults();

        return new RuleSetView($rules->testerMovesDone, $rules->noSelfReview, $rules->reviewBeforeTest);
    }
}
