<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\WaitingTicket;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AiSettingsQueries;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\PeopleCountQueries;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketVisibilities;
use App\Web\Application\HomeFocusChoices;
use App\Web\Application\ShowHomeFocus;
use App\Web\UI\Admin\AdminTabRail;
use App\Web\UI\Home\HomeLayout;
use App\Web\UI\Navigation\MainMenu;
use App\Web\UI\TicketViewers;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home: the work page for anyone who leads or joins a team (and the fallback), "Teams at a glance" for a Manager with
 * no such role, and "Your organization" for an Admin with no team role.
 *
 * Until P07's needs-you score exists, the work page's "Needs you now" lists the open tickets where it is the person's
 * turn from the same read as their card on Whose turn? (HomeQueries::whoseTurn, their group opened), so both count the
 * same tickets: the oldest wait first, at most SHOWN_NEEDS_YOU_NOW, and "See all" to that card. "Your team" shows a
 * stack per team, at most SHOWN_TEAMS. The work page's queries do not grow with the tickets that wait: the turn rows
 * come in one query, the team names in one, and the team people from their cache.
 */
final class HomeController extends AbstractController
{
    public const int SHOWN_NEEDS_YOU_NOW = 10;
    public const int SHOWN_TEAMS = 3;
    /**
     * Ticketing's WhoseTurnController::ROUTE and OPEN_QUERY, and its card id of a person, which Web_UI may not import;
     * HomeNeedsYouNowTest keeps them the same.
     */
    public const string WHOSE_TURN_ROUTE = 'ticketing_whose_turn';
    public const string WHOSE_TURN_OPEN_QUERY = 'open';
    public const string WHOSE_TURN_CARD_ID_PREFIX = 'turn-';
    /** Ticketing's ticket page. */
    public const string TICKET_ROUTE = 'ticket_show';

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly ShowHomeFocus $showHomeFocus,
        private readonly TeamQueries $teamQueries,
        private readonly TeamPeopleQueries $teamPeopleQueries,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly HomeQueries $homeQueries,
        private readonly PeopleCountQueries $peopleCountQueries,
        private readonly AiSettingsQueries $aiSettingsQueries,
        private readonly MainMenu $mainMenu,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/', name: 'web_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $this->denyAccessUnlessGranted(OrganizationPermission::VIEW, $this->tenantContext->get());
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();

        return match (HomeLayout::of($actor->roles())) {
            HomeLayout::Work => $this->workHome($actor),
            HomeLayout::Manager => $this->managerHome($actor),
            HomeLayout::Admin => $this->adminHome($actor),
        };
    }

    private function workHome(ActorContext $actor): Response
    {
        $roles = $actor->roles();
        $viewersGroup = $this->viewersTurnGroupOf($actor);
        $waitingCount = $viewersGroup->ticketCount ?? 0;
        $needsYouNow = \array_slice($viewersGroup->tickets ?? [], 0, self::SHOWN_NEEDS_YOU_NOW);
        $viewerId = $actor->userId()->toString();

        return $this->render('@Web/home/work.html.twig', [
            'today' => $this->clock->now(),
            'choices' => HomeFocusChoices::offeredTo($roles),
            'focus' => ($this->showHomeFocus)($actor),
            'isLead' => array_any($roles->teamIds(), $roles->isLeadOf(...)),
            'isManager' => $roles->isManager(),
            'isAdmin' => $roles->isAdmin(),
            'adminSettingsUrl' => $roles->isAdmin() ? $this->mainMenu->adminHomeUrl() : null,
            'waitingCount' => $waitingCount,
            'needsYouNow' => array_map($this->needsYouNowRowOf(...), $needsYouNow),
            'notShownCount' => $waitingCount - \count($needsYouNow),
            'seeAllUrl' => $this->generateUrl(self::WHOSE_TURN_ROUTE, [
                self::WHOSE_TURN_OPEN_QUERY => $viewerId,
                '_fragment' => self::WHOSE_TURN_CARD_ID_PREFIX.$viewerId,
            ]),
            'teamStacks' => $this->teamStacksOf($actor),
        ]);
    }

    private function managerHome(ActorContext $actor): Response
    {
        return $this->render('@Web/home/manager.html.twig', [
            'today' => $this->clock->now(),
            'teams' => $this->teamQueries->allSummariesOf($actor->organizationId()),
        ]);
    }

    private function adminHome(ActorContext $actor): Response
    {
        $organizationId = $actor->organizationId();

        return $this->render('@Web/home/admin.html.twig', [
            'peopleCount' => $this->peopleCountQueries->peopleCountOf($organizationId),
            'peopleUrl' => $this->generateUrl(AdminTabRail::PEOPLE_ROUTE),
            'teamCount' => $this->teamQueries->countOf($organizationId),
            'isAiOn' => $this->aiSettingsQueries->aiSettingsOf($organizationId)->isOn,
            'aiUrl' => $this->generateUrl(AdminTabRail::AI_ROUTE),
            'adminSettingsUrl' => $this->mainMenu->adminHomeUrl(),
        ]);
    }

    /**
     * @return TurnGroup|null the viewer's card of Whose turn?, with up to HomeQueries::SHOWN_TICKETS_OF_THE_OPENED_GROUP
     *                        tickets; null when nothing waits on them
     */
    private function viewersTurnGroupOf(ActorContext $actor): ?TurnGroup
    {
        $viewerId = $actor->userId();
        $visibility = $this->ticketVisibilities->of($actor->organizationId(), $viewerId);
        $firstGroup = $this->homeQueries->whoseTurn(TicketViewers::of($visibility), null, $viewerId->toString(), null)->groups[0] ?? null;

        return null !== $firstGroup?->turnUserId && $firstGroup->turnUserId->equals($viewerId) ? $firstGroup : null;
    }

    /**
     * @return array{key: string, title: string, url: string, wait: string}
     */
    private function needsYouNowRowOf(WaitingTicket $ticket): array
    {
        $waitDays = max(0, intdiv($this->clock->now()->getTimestamp() - $ticket->waitingSince->getTimestamp(), 86400));

        return [
            'key' => $ticket->key,
            'title' => $ticket->title,
            'url' => $this->generateUrl(self::TICKET_ROUTE, ['key' => $ticket->key]),
            'wait' => match ($waitDays) {
                0 => 'today',
                1 => 'waiting 1 day',
                default => 'waiting '.$waitDays.' days',
            },
        ];
    }

    /**
     * @return list<array{name: string, names: list<string>, peopleText: string, url: string}> the first SHOWN_TEAMS of
     *                                                                                         the person's teams by name
     */
    private function teamStacksOf(ActorContext $actor): array
    {
        $teams = \array_slice($this->teamQueries->summariesOf($actor->organizationId(), $actor->roles()->teamIds()), 0, self::SHOWN_TEAMS);
        $stacks = [];
        foreach ($teams as $team) {
            $people = $this->teamPeopleQueries->namedPossibleAssigneesOf($team->id());
            $stacks[] = [
                'name' => $team->name(),
                'names' => array_map(static fn (PossibleAssignee $person): string => $person->displayName, $people),
                'peopleText' => 1 === \count($people) ? '1 person' : number_format(\count($people)).' people',
                'url' => $this->generateUrl(TeamController::ROUTE, ['slug' => $team->slug()]),
            ];
        }

        return $stacks;
    }
}
