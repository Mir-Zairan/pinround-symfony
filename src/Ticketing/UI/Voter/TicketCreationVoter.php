<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides TicketPermission CREATE and ASSIGN for the team a new ticket is for. CREATE: a user with a team role (lead,
 * member or viewer) on that team. ASSIGN, giving the new ticket to someone: a Lead of that team only, as TicketVoter
 * ASSIGN on a ticket. A Manager or an Admin without a role on the team is denied both, as phase 3's criteria say for
 * the team ticket form; the any-role "Report a problem" path of ADR-0013 is the intake's, not this voter's. A team
 * role is held in the actor's own organization only, so another organization's team is denied. These are TicketVoter's
 * attributes on another subject, a TeamId, which DenyByDefaultVoter lets a voter decide only one class of.
 *
 * @extends DenyByDefaultVoter<TeamId>
 */
final class TicketCreationVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor)
    {
    }

    protected function attributes(): array
    {
        return [TicketPermission::CREATE, TicketPermission::ASSIGN];
    }

    protected function subjectClass(): string
    {
        return TeamId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $roles = $this->currentActor->actorFor($token->getUser())?->roles();
        if (null === $roles) {
            return false;
        }

        return match ($attribute) {
            TicketPermission::ASSIGN => $roles->isLeadOf($subject),
            TicketPermission::CREATE => $roles->isInTeam($subject),
            default => false,
        };
    }
}
