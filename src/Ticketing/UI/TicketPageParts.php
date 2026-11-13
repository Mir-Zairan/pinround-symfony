<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use App\Workflow\Api\TurnInputs;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the ticket page shows of the ticket's step: the header's state (the step's pill, Now and Next, the group line)
 * and the "Your next step" box, and the People section. TicketShowController renders them in the page,
 * TicketMoveController's Turbo Stream answer renders them again after a move, both through _ticket_state.html.twig,
 * _next_step.html.twig and _people.html.twig.
 *
 * One query for the saved turn with the waiting turns (NowNextSentences::pageTurnOf), and a lead's move targets,
 * which are cached.
 */
final readonly class TicketPageParts
{
    /** The ids of the parts' elements, which the Turbo Stream answer of a move replaces. */
    public const string STATE_ID = 'ticket-state';
    public const string NEXT_STEP_ID = 'ticket-next-step';
    public const string PEOPLE_ID = 'ticket-people';

    /** Workflow's group codes to StatusPill's groups. */
    private const array PILL_GROUPS = ['todo' => 'todo', 'doing' => 'doing', 'check' => 'checking', 'wait' => 'waiting', 'done' => 'done'];

    public function __construct(
        private NowNextSentences $nowNextSentences,
        private NextStepChoices $nextStepChoices,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TicketPeople $ticketPeople,
    ) {
    }

    /**
     * The templates' variables; turnLines and nextStep are null for a ticket no team owns, which has no turn.
     *
     * @return array{ticket: TicketPageView, pillGroup: string, turnLines: array{now: string, next: string|null}|null, nextStep: NextStep|null, people: list<TicketPerson>}
     */
    public function of(TicketPageView $ticket, ActorContext $viewer): array
    {
        $pageTurn = $this->nowNextSentences->pageTurnOf($ticket);

        return [
            'ticket' => $ticket,
            'pillGroup' => self::pillGroupOf($ticket->groupCode),
            'turnLines' => null === $pageTurn ? null : $this->nowNextSentences->linesOf($pageTurn),
            'nextStep' => null === $pageTurn ? null : $this->nextStepChoices->of($pageTurn, $viewer, $this->authorizationChecker->isGranted(TicketPermission::MOVE, $ticket->visibilityFacts)),
            'people' => $this->ticketPeople->of(
                $pageTurn->ticket ?? TurnInputs::ticketStateOf($ticket->turnSnapshot),
                $pageTurn->context->firstNames ?? $ticket->displayNames,
                $pageTurn?->team,
                $pageTurn?->turn,
                $viewer->userId(),
            ),
        ];
    }

    /**
     * @param string $groupCode one of Workflow's group codes: todo, doing, check, wait, done
     *
     * @return string the StatusPill group of the step's group, as Ticketing_UI may not import Web_UI's Group
     *
     * @throws \LogicException for a group code Workflow does not have
     */
    public static function pillGroupOf(string $groupCode): string
    {
        return self::PILL_GROUPS[$groupCode] ?? throw new \LogicException(\sprintf('A step group "%s" has no status pill.', $groupCode));
    }
}
