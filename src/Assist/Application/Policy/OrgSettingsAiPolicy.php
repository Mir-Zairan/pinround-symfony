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
            return $this->viewOf(AiSettings::off(), PolicyRefusal::AiOff, null);
        }

        $settings = $this->aiSettingsQueries->aiSettingsOf($viewer->organizationId());

        return $this->viewOf($settings, $this->policyEvaluator->refusalForViewer($settings, $viewer), $viewer);
    }

    public function for(UserId $viewerId, OrganizationId $organizationId): PolicyView
    {
        $settings = $this->aiSettingsQueries->aiSettingsOf($organizationId);
        $roles = $this->policyEvaluator->needsViewerRoles($settings) || self::isLaptopAiOn($settings)
            ? $this->membershipQueries->rolesOf($viewerId, $organizationId)
            : Roles::none();
        $viewer = new ActorContext($viewerId, $organizationId, $roles);

        return $this->viewOf($settings, $this->policyEvaluator->refusalForViewer($settings, $viewer), $viewer);
    }

    public function serverTicketRefusalOf(PolicyView $policy, bool $isTicketTeamOnly): ?string
    {
        if (null !== $policy->refusalSentence) {
            return $policy->refusalSentence;
        }
        $decision = $this->policyEvaluator
            ->decideRoute($policy->whereAiRuns, $policy->isRestrictedToCloudAllowed, $policy->isTitleOnly, $isTicketTeamOnly, isLaptopReady: false);
        if (PolicyRefusal::LaptopNotReady === $decision->refusal) {
            // As AiTaskAdmission::admitTicket: the setup line points to the Local AI chip, which this person never gets.
            return $policy->laptopViewerRefusalSentence ?? $decision->reason();
        }

        return $decision->reason();
    }

    public function serverDraftRefusalOf(PolicyView $policy): ?string
    {
        // AiTaskAdmission decides a draft as a ticket that is not team-only.
        return $policy->refusalSentence ?? $this->policyEvaluator
            ->decideRoute($policy->whereAiRuns, $policy->isRestrictedToCloudAllowed, $policy->isTitleOnly, isTicketRestricted: false, isLaptopReady: false)
            ->refusal?->forDraft()->sentence();
    }

    /**
     * @param ActorContext|null $viewer null for a token user not logged in to an organization
     */
    private function viewOf(AiSettings $settings, ?PolicyRefusal $refusal, ?ActorContext $viewer): PolicyView
    {
        $laptopViewerRefusal = null !== $viewer && self::isLaptopAiOn($settings) ? $this->policyEvaluator->refusalForLaptopViewer($viewer) : null;

        return new PolicyView(
            $refusal?->value,
            $refusal?->sentence(),
            $settings->whereAiRuns,
            $settings->isTitleOnly,
            $settings->isOn && $settings->isSummaryOnSyncOn,
            $settings->isRestrictedToCloudAllowed,
            $laptopViewerRefusal?->sentence(),
        );
    }

    private static function isLaptopAiOn(AiSettings $settings): bool
    {
        return $settings->isOn && AiSettings::WHERE_CLOUD !== $settings->whereAiRuns;
    }
}
