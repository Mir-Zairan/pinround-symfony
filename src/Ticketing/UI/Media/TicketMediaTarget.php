<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Media;

use App\Media\Api\MediaTargetAuthorizer;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lets a person attach an upload to a ticket, by its id, that TicketPermission VIEW and EDIT grant them (P05.T1): a
 * Lead or Member of the ticket's team who may see it. The voters decide for the request's logged-in actor, so anyone
 * else asked about, and an organization other than the actor's, is refused. A malformed id, a ticket the tenant has
 * not and a ticket whose facts cannot be read are refused too: every gap fails closed.
 */
final readonly class TicketMediaTarget implements MediaTargetAuthorizer
{
    public const string KIND = 'ticket';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private TicketTurnQueries $ticketTurnQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function targetKind(): string
    {
        return self::KIND;
    }

    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool
    {
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser());
        if (null === $actor || !$actor->userId()->equals($userId) || !$actor->organizationId()->equals($organizationId)) {
            return false;
        }
        try {
            $ticketId = new TicketId($targetId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        try {
            $ticket = $this->ticketTurnQueries->factsOf($ticketId);
        } catch (\LogicException) {
            return false;
        }
        if (null === $ticket) {
            return false;
        }
        $visibilityFacts = TicketVisibilityFacts::ofSnapshot($organizationId, $ticket);

        return $this->authorizationChecker->isGranted(TicketPermission::VIEW, $visibilityFacts)
            && $this->authorizationChecker->isGranted(TicketPermission::EDIT, $visibilityFacts);
    }
}
