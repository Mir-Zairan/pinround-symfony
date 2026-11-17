<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Finds a ticket by key for the logged-in person, with the ticket page's 404 for one they may not see, so no answer of
 * Review's ticket routes tells that a hidden ticket exists.
 */
final readonly class VisibleTicketLookup
{
    public function __construct(
        private TenantContext $tenantContext,
        private CurrentActor $currentActor,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * Call it inside a transaction: outside one, CurrentActor finds no roles under row-level security.
     *
     * @throws AccessDeniedException when OrganizationPermission VIEW is denied or no one is logged in
     * @throws NotFoundHttpException with RoundsController::NOT_FOUND for an unknown key or a ticket TicketPermission
     *                               VIEW denies
     */
    public function visibleTicketOf(string $key): VisibleTicket
    {
        if (!$this->authorizationChecker->isGranted(OrganizationPermission::VIEW, $this->tenantContext->get())) {
            throw new AccessDeniedException();
        }
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser()) ?? throw new AccessDeniedException();
        $visibility = $this->ticketVisibilities->of($actor->organizationId(), $actor->userId());
        $ticket = $this->ticketQueries->pageOf($key, $visibility);
        if (null === $ticket || !$this->authorizationChecker->isGranted(TicketPermission::VIEW, $ticket->visibilityFacts)) {
            throw new NotFoundHttpException(RoundsController::NOT_FOUND);
        }

        return new VisibleTicket($actor, $visibility, $ticket);
    }
}
