<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Media;

use App\Media\Api\MediaTargetAuthorizer;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPermission;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lets a person upload the screenshot of a ticket they are about to make (P05.T5), by the id of a team in which
 * TicketPermission CREATE grants them new tickets. There is no ticket yet, so the team is the target; the report checks
 * again when it is saved that the picture is the reporter's own. The decision is for the request's logged-in actor, so
 * anyone else asked about, and an organization other than the actor's, is refused, as is a malformed id.
 */
final readonly class NewTicketMediaTarget implements MediaTargetAuthorizer
{
    public const string KIND = 'new_ticket';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
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
            $teamId = new TeamId($targetId);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->authorizationChecker->isGranted(TicketPermission::CREATE, $teamId);
    }
}
