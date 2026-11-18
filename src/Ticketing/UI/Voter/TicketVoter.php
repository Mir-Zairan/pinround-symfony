<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\RoleTicketVisibilities;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides TicketPermission VIEW, EDIT, MOVE, ASSIGN and CHOOSE_MAIN_SUMMARY on one ticket (ADR-0013,
 * personas-and-roles.md) from the request's actor. VIEW is the ticket visibility predicate, nothing else. EDIT and MOVE
 * are a Lead's or Member's of the ticket's team, ASSIGN a Lead's only: a Viewer, and a Manager or Admin with no such team
 * role, is denied, and so is everyone for a ticket with no team. CHOOSE_MAIN_SUMMARY is the Lead's of the ticket's team
 * or its reporter's, both of whom the visibility predicate lets see it. A ticket of another organization is denied for every attribute.
 *
 * VIEW reads the organization's triager, so it needs the request's open transaction under the actor's organization.
 *
 * @extends DenyByDefaultVoter<TicketVisibilityFacts>
 */
final class TicketVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor, private readonly RoleTicketVisibilities $ticketVisibilities)
    {
    }

    protected function attributes(): array
    {
        return [TicketPermission::VIEW, TicketPermission::EDIT, TicketPermission::MOVE, TicketPermission::ASSIGN, TicketPermission::CHOOSE_MAIN_SUMMARY];
    }

    protected function subjectClass(): string
    {
        return TicketVisibilityFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor || !$subject->organizationId->equals($actor->organizationId())) {
            return false;
        }
        if (TicketPermission::VIEW === $attribute) {
            return $this->ticketVisibilities->ofActor($actor)->isVisible($subject);
        }
        if (TicketPermission::CHOOSE_MAIN_SUMMARY === $attribute) {
            // The visibility predicate lets both see the ticket, so VIEW needs no second read here.
            return $subject->reporterId->equals($actor->userId()) || (null !== $subject->teamId && $actor->roles()->isLeadOf($subject->teamId));
        }
        if (null === $subject->teamId) {
            return false;
        }
        $roles = $actor->roles();

        return match ($attribute) {
            TicketPermission::EDIT, TicketPermission::MOVE => $roles->isLeadOf($subject->teamId) || $roles->isMemberOf($subject->teamId),
            TicketPermission::ASSIGN => $roles->isLeadOf($subject->teamId),
            default => false,
        };
    }
}
