<?php

declare(strict_types=1);

namespace App\Notification\UI\Voter;

use App\Notification\Api\PingPermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides PingPermission SEND on one ticket (personas-and-roles.md, "Ping a person"): only someone TicketPermission VIEW
 * lets see the ticket, and of them an Admin, a Manager, a Lead or Member of the ticket's team, or its reporter. A
 * Viewer of the team is denied, also when they reported the ticket, and so is anyone else the ticket shows to only
 * because they work on it. The role rule is Roles::mayAskOrPingOn, which ConversationVoter's ASK shares. Denied with
 * nobody logged in, with no tenant, and for a ticket of another organization.
 *
 * Reads the actor's roles under row-level security, so it needs the request's open transaction under the tenant.
 *
 * @extends DenyByDefaultVoter<TicketVisibilityFacts>
 */
final class PingVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [PingPermission::SEND];
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
        if (!$this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $subject)) {
            return false;
        }

        return $actor->roles()->mayAskOrPingOn($subject->teamId, $subject->reporterId->equals($actor->userId()));
    }
}
