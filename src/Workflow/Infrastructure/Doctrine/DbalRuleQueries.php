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

    public function rulesFor(string $teamId): RuleSetView
    {
        $team = new TeamId($teamId);
        $organizationId = $this->tenantContext->require();

        $teamRules = StepPolicy::Team === $this->settingsQueries->stepPolicyOf($organizationId)
            ? $this->ruleSets->forTeam($organizationId, $team)
            : null;
        $rules = $teamRules ?? $this->ruleSets->forOrganization($organizationId) ?? RuleSet::defaults();

        return new RuleSetView($rules->testerMovesDone, $rules->noSelfReview, $rules->reviewBeforeTest);
    }
}
