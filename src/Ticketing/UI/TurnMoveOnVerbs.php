<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\CurrentActor;
use App\Ticketing\Api\MoveOnForm;
use App\Ticketing\Api\MoveOnVerb;
use App\Ticketing\Api\MoveOnVerbQueries;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Application\SavedTicketPageTurns;
use App\Workflow\Api\TransitionView;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The move-on verbs of the Your next step box for the person whose turn it is, for the round page: the same turn the
 * ticket page reads (SavedTicketPageTurns::pageTurnOf) and the same verbs (NextStepChoices), with TicketMoveController's
 * form token, so a move from the round page is the ticket page's move. Holds no state: the actor comes from
 * CurrentActor, which keeps it per request.
 */
final readonly class TurnMoveOnVerbs implements MoveOnVerbQueries
{
    public function __construct(
        private CurrentActor $currentActor,
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorizationChecker,
        private SavedTicketPageTurns $savedTicketPageTurns,
        private NextStepChoices $nextStepChoices,
    ) {
    }

    public function moveOnFormOf(TicketPageView $ticket): ?MoveOnForm
    {
        if (!$this->authorizationChecker->isGranted(TicketPermission::MOVE, $ticket->visibilityFacts)) {
            return null;
        }
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser());
        if (null === $actor) {
            return null;
        }
        $pageTurn = $this->savedTicketPageTurns->pageTurnOf($ticket);
        $turnUserId = $pageTurn?->turn->userId;
        if (null === $pageTurn || null === $turnUserId || !$turnUserId->equals($actor->userId())) {
            return null;
        }
        $verbs = array_map(
            static fn (TransitionView $transition): MoveOnVerb => new MoveOnVerb($transition->verb, $transition->label, $transition->isPrimary),
            $this->nextStepChoices->moveOnTransitionsOf($pageTurn, $actor),
        );
        if ([] === $verbs) {
            return null;
        }

        return new MoveOnForm($ticket->key, $ticket->version, TicketMoveController::csrfIdOf($ticket->key), $verbs);
    }
}
