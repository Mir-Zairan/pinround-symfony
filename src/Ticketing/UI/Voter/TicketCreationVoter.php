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
 * Decides TicketPermission CREATE for the team a new ticket is for: a user with a team role (lead, member or viewer)
 * on that team. A Manager or an Admin without a role on the team is denied, as phase 3's criteria say for the team
 * ticket form; the any-role "Report a problem" path of ADR-0013 is the intake's, not this voter's. A team role is held
 * in the actor's own organization only, so another organization's team is denied. It is TicketVoter's attribute on
 * another subject, a TeamId, which DenyByDefaultVoter lets a voter decide only one class of.
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
        return [TicketPermission::CREATE];
    }

    protected function subjectClass(): string
    {
        return TeamId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());

        return null !== $actor && $actor->roles()->isInTeam($subject);
    }
}
