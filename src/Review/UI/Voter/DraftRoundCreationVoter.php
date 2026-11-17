<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketTurnQueries;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides RoundPermission DRAFT_CREATE on a ticket (ADR-0017): its current assignee may start a private draft round,
 * while the ticket is not in Done. Nobody else may, not a lead or an admin either. Denied with nobody logged in, when
 * the tenant is not the actor's organization, and for a ticket the tenant has not or whose facts cannot be read. It is
 * apart from RoundVoter because its subject is a TicketId, and DenyByDefaultVoter lets a voter decide only one class.
 *
 * Reads the ticket under row-level security, so it needs the request's open transaction under the actor's organization.
 *
 * @extends DenyByDefaultVoter<TicketId>
 */
final class DraftRoundCreationVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly TicketTurnQueries $ticketTurnQueries,
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

        return null !== $ticket->assigneeId && $ticket->assigneeId->equals($actor->userId());
    }
}
