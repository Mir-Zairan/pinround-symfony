<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

use App\Assist\Api\AiPolicy;
use App\Assist\Api\PolicyView;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

final readonly class OrgSettingsAiPolicy implements AiPolicy
{
    public function __construct(
        private AiSettingsQueries $aiSettingsQueries,
        private MembershipQueries $membershipQueries,
        private PolicyEvaluator $policyEvaluator,
    ) {
    }

    public function for(UserId $viewerId, OrganizationId $organizationId): PolicyView
    {
        $settings = $this->aiSettingsQueries->aiSettingsOf($organizationId);
        $refusal = $this->policyEvaluator->refusalForViewer(
            $settings,
            new ActorContext($viewerId, $organizationId, $this->membershipQueries->rolesOf($viewerId, $organizationId)),
        );

        return new PolicyView(
            $refusal?->value,
            $refusal?->sentence(),
            $settings->whereAiRuns,
            $settings->isTitleOnly,
            $settings->isOn && $settings->isSummaryOnSyncOn,
            $settings->isRestrictedToCloudAllowed,
        );
    }
}
