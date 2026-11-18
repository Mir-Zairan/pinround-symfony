<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettings;
use App\SharedKernel\Domain\TeamId;

/**
 * The decision flow of ai-architecture.md, "Org AI policy", for every AI request: AI on, the viewer allowed, the
 * ticket visible, then where it runs. Budget and rate limits come after it, in the gateway.
 *
 * "Leads only" lets in a person who leads at least one team; an admin or manager who leads none is refused, as the
 * setting names leads. "Title only" applies to the laptop route too: the admin's choice is about what any AI is given.
 */
final readonly class PolicyEvaluator
{
    /**
     * The checks that need no ticket, for the AI buttons of a page: AI on, and the viewer allowed.
     */
    public function refusalForViewer(AiSettings $settings, ActorContext $viewer): ?PolicyRefusal
    {
        if (!$settings->isOn) {
            return PolicyRefusal::AiOff;
        }
        if ($this->needsViewerRoles($settings) && !self::isLeadOfAnyTeam($viewer)) {
            return PolicyRefusal::LeadsOnly;
        }

        return null;
    }

    /**
     * Whether the viewer's roles change the answer, so a caller that has none at hand reads them only then. When not,
     * any roles, Roles::none() included, give the same decision.
     */
    public function needsViewerRoles(AiSettings $settings): bool
    {
        return $settings->isOn && $settings->isForLeadsOnly;
    }

    /**
     * @param bool $canSeeTicket       what the caller's ticket voter answered for the viewer
     * @param bool $isTicketRestricted the ticket's visibility is team (team-only)
     * @param bool $isLaptopReady      what the viewer's browser reported about Ollama on the laptop
     */
    public function decide(AiSettings $settings, ActorContext $viewer, bool $canSeeTicket, bool $isTicketRestricted, bool $isLaptopReady): PolicyDecision
    {
        $refusal = $this->refusalForViewer($settings, $viewer);
        if (null !== $refusal) {
            return PolicyDecision::refuse($refusal);
        }
        if (!$canSeeTicket) {
            return PolicyDecision::refuse(PolicyRefusal::CannotSeeTicket);
        }

        return $this->decideRoute($settings->whereAiRuns, $settings->isRestrictedToCloudAllowed, $settings->isTitleOnly, $isTicketRestricted, $isLaptopReady);
    }

    /**
     * The last step of decide(), for a viewer already allowed AI help on a ticket they may see: the laptop when it is
     * ready and allowed, the setup sheet for "laptop only", then a team-only ticket kept from cloud AI, then the cloud.
     * A page that holds the viewer's policy but not their roles decides the step here too (AiPolicy::serverTicketRefusalOf).
     *
     * @param string $whereAiRuns one of AiSettings::WHERE_VALUES
     */
    public function decideRoute(string $whereAiRuns, bool $isRestrictedToCloudAllowed, bool $isTitleOnly, bool $isTicketRestricted, bool $isLaptopReady): PolicyDecision
    {
        if (AiSettings::WHERE_CLOUD !== $whereAiRuns && $isLaptopReady) {
            return PolicyDecision::send(AiRoute::Laptop, $isTitleOnly);
        }
        if (AiSettings::WHERE_LAPTOP === $whereAiRuns) {
            return PolicyDecision::setupSheet();
        }

        if ($isTicketRestricted && !$isRestrictedToCloudAllowed) {
            return PolicyDecision::refuse(PolicyRefusal::RestrictedTicketToCloud);
        }

        return PolicyDecision::send(AiRoute::Cloud, $isTitleOnly);
    }

    private static function isLeadOfAnyTeam(ActorContext $viewer): bool
    {
        $roles = $viewer->roles();

        return array_any($roles->teamIds(), static fn (TeamId $teamId): bool => $roles->isLeadOf($teamId));
    }
}
