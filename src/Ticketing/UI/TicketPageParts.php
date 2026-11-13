<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the ticket page shows of the ticket's step: the header's state (the step's pill, Now and Next, the group line)
 * and the "Your next step" box. TicketShowController renders them in the page, TicketMoveController's Turbo Stream
 * answer renders them again after a move, both through _ticket_state.html.twig and _next_step.html.twig.
 *
 * One query for the saved turn with the waiting turns (NowNextSentences::pageTurnOf), and a lead's move targets,
 * which are cached.
 */
final readonly class TicketPageParts
{
    /** The ids of the two parts' elements, which the Turbo Stream answer of a move replaces. */
    public const string STATE_ID = 'ticket-state';
    public const string NEXT_STEP_ID = 'ticket-next-step';

    public function __construct(
        private NowNextSentences $nowNextSentences,
        private NextStepChoices $nextStepChoices,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * The two templates' variables; turnLines and nextStep are null for a ticket no team owns, which has no turn.
     *
     * @return array{ticket: TicketPageView, pillGroup: string, turnLines: array{now: string, next: string|null}|null, nextStep: NextStep|null}
     */
    public function of(TicketPageView $ticket, ActorContext $viewer): array
    {
        $pageTurn = $this->nowNextSentences->pageTurnOf($ticket);

        return [
            'ticket' => $ticket,
            'pillGroup' => TicketShowController::pillGroupOf($ticket->groupCode),
            'turnLines' => null === $pageTurn ? null : $this->nowNextSentences->linesOf($pageTurn),
            'nextStep' => null === $pageTurn ? null : $this->nextStepChoices->of($pageTurn, $viewer, $this->authorizationChecker->isGranted(TicketPermission::MOVE, $ticket->visibilityFacts)),
        ];
    }
}
