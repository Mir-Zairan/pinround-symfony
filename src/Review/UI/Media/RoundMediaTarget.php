<?php

declare(strict_types=1);

namespace App\Review\UI\Media;

use App\Media\Api\MediaTargetAuthorizer;
use App\Organization\Api\CurrentActor;
use App\Review\Application\RoundUploadTarget;
use App\Review\Application\RoundUploadTargets;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lets a person add an upload to an open round, by its id (P05.T4). An unshared draft takes uploads from its owner
 * alone, not even from a lead or an admin (ADR-0017); any other round from whoever TicketPermission VIEW and EDIT grant
 * its ticket. The decision is for the request's logged-in actor, so anyone else asked about, and an organization other
 * than the actor's, is refused. A malformed id, a round the tenant has not, a closed round and a round or ticket that
 * cannot be read are refused too: every gap fails closed.
 */
final readonly class RoundMediaTarget implements MediaTargetAuthorizer
{
    public const string KIND = 'round';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private RoundUploadTargets $roundUploadTargets,
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
            $round = $this->roundUploadTargets->of($targetId);
        } catch (\LogicException) {
            return false;
        }
        if (null === $round || !$round->isOpen) {
            return false;
        }
        if ($round->isUnsharedDraft) {
            return $round->ownerId->equals($userId);
        }

        return $this->mayEditTicketOf($round, $organizationId);
    }

    private function mayEditTicketOf(RoundUploadTarget $round, OrganizationId $organizationId): bool
    {
        try {
            $ticket = $this->ticketTurnQueries->factsOf($round->ticketId);
        } catch (\LogicException) {
            return false;
        }
        if (null === $ticket) {
            return false;
        }
        $visibilityFacts = new TicketVisibilityFacts(
            $organizationId,
            $ticket->visibility,
            $ticket->teamId,
            $ticket->reporterId,
            $ticket->assigneeId,
            $ticket->pendingAssigneeId,
            $ticket->reviewerId,
            $ticket->testerId,
            $ticket->waitingOnUserId,
        );

        return $this->authorizationChecker->isGranted(TicketPermission::VIEW, $visibilityFacts)
            && $this->authorizationChecker->isGranted(TicketPermission::EDIT, $visibilityFacts);
    }
}
