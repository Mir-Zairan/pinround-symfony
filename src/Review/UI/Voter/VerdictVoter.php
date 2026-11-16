<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\VerdictPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides VerdictPermission SET on one round (ADR-0013, FR-RND-05): the ticket's tester, or any tester of its team
 * while the ticket has none stored, and the lead of its team, while TicketPermission VIEW and EDIT both grant them the
 * ticket. The assignee, other members, a Manager and an Admin with no such role are denied. Denied with nobody logged
 * in, when the tenant is not the actor's organization, for a round of another organization, for an unshared or deleted
 * draft (the ticket is not read for those), and when the ticket's facts cannot be read.
 *
 * Reads the round's ticket under row-level security, so it needs the request's open transaction under the actor's
 * organization.
 *
 * @extends DenyByDefaultVoter<RoundAccessFacts>
 */
final class VerdictVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [VerdictPermission::SET];
    }

    protected function subjectClass(): string
    {
        return RoundAccessFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }
        if (!$subject->organizationId->equals($actor->organizationId()) || $subject->isDeleted || $subject->isUnsharedDraft) {
            return false;
        }
        $ticket = $this->ticketOf($subject);
        if (null === $ticket || null === $ticket->teamId) {
            return false;
        }
        $isLead = $actor->roles()->isLeadOf($ticket->teamId);
        if (!$isLead && !TicketTesterRule::isTesterOf($ticket, $actor->userId())) {
            return false;
        }
        $visibilityFacts = TicketVisibilityFacts::ofSnapshot($subject->organizationId, $ticket);

        return $this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $visibilityFacts)
            && $this->accessDecisionManager->decide($token, [TicketPermission::EDIT], $visibilityFacts);
    }

    private function ticketOf(RoundAccessFacts $round): ?TicketTurnSnapshot
    {
        try {
            return $this->ticketTurnQueries->factsOf($round->ticketId);
        } catch (\LogicException) {
            return null;
        }
    }
}
