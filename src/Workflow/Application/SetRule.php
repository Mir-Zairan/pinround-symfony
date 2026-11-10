<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\RuleSets;

/**
 * Turns one rule on or off (workflow-engine.md, "Rules (guards)"): in the organization's rule set (no team), or in a
 * team's own rule set, which exists only under the step policy team. Its writes share the caller's transaction, begun
 * under $organizationId. Who may set it is the voter's question, asked by the UI: Organization's ORG_ADMIN for the
 * organization's rules, WorkflowPermission::RULES_CHANGE_TEAM for a team's.
 */
final readonly class SetRule
{
    public function __construct(private RuleSets $ruleSets, private SettingsQueries $settingsQueries, private TeamQueries $teamQueries)
    {
    }

    /**
     * A team's first own rule set starts as a copy of the organization's, then the one rule changes. An organization
     * without a rule set starts from the defaults. Only the one rule is written, so a rule someone else switched at the
     * same time is kept.
     *
     * @param string $ruleCode one of tester_moves_done, no_self_review, review_before_test
     *
     * @throws RuleUnknown             when the rule code is not one of the three; nothing is saved
     * @throws RuleTeamNotFound        when the team is not a team of the organization; nothing is saved
     * @throws TeamRulesNeedTeamPolicy when a team rule is set under a step policy other than team; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, ?TeamId $teamId, string $ruleCode, bool $isOn): void
    {
        if (!\in_array($ruleCode, RuleSet::RULE_CODES, true)) {
            throw new RuleUnknown(\sprintf('There is no rule "%s".', $ruleCode));
        }

        if (null !== $teamId) {
            $isTeamOfTheOrganization = array_any(
                $this->teamQueries->teamsOf($organizationId),
                static fn (TeamId $organizationTeam): bool => $organizationTeam->equals($teamId),
            );
            if (!$isTeamOfTheOrganization) {
                throw new RuleTeamNotFound('That team is not in this organization.');
            }
            if (StepPolicy::Team !== $this->settingsQueries->stepPolicyOf($organizationId)) {
                throw new TeamRulesNeedTeamPolicy('Only when your admin lets teams decide does a team set its own rules.');
            }
        }

        $startingRules = null === $teamId
            ? RuleSet::defaults()
            : $this->ruleSets->forOrganization($organizationId) ?? RuleSet::defaults();
        $this->ruleSets->switchRule($organizationId, $teamId, $ruleCode, $isOn, $startingRules);
    }
}
