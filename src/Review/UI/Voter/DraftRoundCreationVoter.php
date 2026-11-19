<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Insight\Api\TurnUserQueries;
use App\Organization\Api\CurrentActor;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides RoundPermission DRAFT_CREATE on a ticket (ADR-0017, ADR-0044): while the ticket is not in Done, its current
 * assignee may start a private draft round, and so may the person whose turn it is in Insight's turn read model (the
 * tester in Testing, the reviewer in Code review), when TicketPermission VIEW also lets them see the ticket. Nobody else
 * may, not a lead or an admin either. Denied with nobody logged in, when the tenant is not the actor's organization,
 * and for a ticket the tenant has not or whose facts or turn cannot be read. It is apart from RoundVoter because its
 * subject is a TicketId, and DenyByDefaultVoter lets a voter decide only one class.
 *
 * Reads the ticket, and for anyone but the assignee its turn row, under row-level security, so it needs the request's
 * open transaction under the actor's organization.
 *
 * @extends DenyByDefaultVoter<TicketId>
 */
final class DraftRoundCreationVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly TurnUserQueries $turnUserQueries,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [RoundPermission::DRAFT_CREATE];
    }

    protected function subjectClass(): string
    {
        return TicketId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }
        try {
            $ticket = $this->ticketTurnQueries->factsOf($subject);
        } catch (\LogicException) {
            return false;
        }
        if (null === $ticket || TicketListGroup::DONE === $ticket->groupCode) {
            return false;
        }

        if (null !== $ticket->assigneeId && $ticket->assigneeId->equals($actor->userId())) {
            return true;
        }
        if (!$this->isTurnOf($subject, $actor->userId())) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [TicketPermission::VIEW], TicketVisibilityFacts::ofSnapshot($actor->organizationId(), $ticket));
    }

    private function isTurnOf(TicketId $ticketId, UserId $userId): bool
    {
        try {
            $turnUser = $this->turnUserQueries->turnUsersOf([$ticketId])[$ticketId->toString()] ?? null;
        } catch (\LogicException) {
            return false;
        }

        return null !== $turnUser && $turnUser->equals($userId);
    }
}
