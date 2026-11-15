<?php

declare(strict_types=1);

namespace App\Notification\UI\Voter;

use App\Notification\Api\MutePermission;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides MutePermission MUTE on one ticket: anyone TicketPermission VIEW lets see the ticket may mute and unmute it
 * for themselves, a Viewer of its team included. Denied with nobody logged in, with no tenant, and for a ticket of
 * another organization.
 *
 * Reads the actor's roles under row-level security, so it needs the request's open transaction under the tenant.
 *
 * @extends DenyByDefaultVoter<TicketVisibilityFacts>
 */
final class MuteVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [MutePermission::MUTE];
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

        return $this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $subject);
    }
}
