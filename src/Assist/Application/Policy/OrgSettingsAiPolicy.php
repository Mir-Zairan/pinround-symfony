<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

use App\Assist\Api\AiPolicy;
use App\Assist\Api\PolicyView;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

final readonly class OrgSettingsAiPolicy implements AiPolicy
{
    public function __construct(
        private AiSettingsQueries $aiSettingsQueries,
        private CurrentActor $currentActor,
        private MembershipQueries $membershipQueries,
        private PolicyEvaluator $policyEvaluator,
    ) {
    }

    public function forTokenUser(mixed $tokenUser): PolicyView
    {
        $viewer = $this->currentActor->actorFor($tokenUser);
        if (null === $viewer) {
            return $this->viewOf(AiSettings::off(), PolicyRefusal::AiOff);
        }

        $settings = $this->aiSettingsQueries->aiSettingsOf($viewer->organizationId());

        return $this->viewOf($settings, $this->policyEvaluator->refusalForViewer($settings, $viewer));
    }

    public function for(UserId $viewerId, OrganizationId $organizationId): PolicyView
    {
        $settings = $this->aiSettingsQueries->aiSettingsOf($organizationId);
        $roles = $this->policyEvaluator->needsViewerRoles($settings) ? $this->membershipQueries->rolesOf($viewerId, $organizationId) : Roles::none();

        return $this->viewOf($settings, $this->policyEvaluator->refusalForViewer($settings, new ActorContext($viewerId, $organizationId, $roles)));
    }

    private function viewOf(AiSettings $settings, ?PolicyRefusal $refusal): PolicyView
    {
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
