<?php

declare(strict_types=1);

namespace App\Web\UI\Navigation;

use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\TeamId;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The header's main menu and its "Report a problem" button and bell, from the current user's roles in the current
 * organization (frontend.md, Layout and navigation). Showing an item grants nothing: each page asks its own voter.
 *
 * The route names below are the ones the later phases give their pages. Until a phase adds its route, the item renders
 * inert; once the route exists it becomes a link with no change here. Holds no state, so it is safe in worker mode.
 */
final readonly class MainMenu
{
    public const string HOME_ROUTE = 'web_home';
    public const string TICKETS_ROUTE = 'ticketing_tickets';
    public const string MY_REPORTS_ROUTE = 'ticketing_my_reports';
    /** Takes the team's id as the route parameter "teamId". */
    public const string TEAM_ROUTE = 'organization_team';
    /** The admin home is its People tab. */
    public const string ADMIN_ROUTE = 'organization_admin_people';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private TeamQueries $teamQueries,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Ask it inside the request transaction, as CurrentActor needs; without an actor (logged out, no organization
     * chosen yet) the menu is Home alone.
     *
     * @return list<MenuItem> in this order, each when the roles allow it: Home, Tickets, My reports, Team (or the
     *                        "Teams" group for two or more teams), Admin
     */
    #[AsTwigFunction('main_menu')]
    public function items(): array
    {
        $items = [$this->pageItem('Home', self::HOME_ROUTE)];
        $actor = $this->actor();
        if (null === $actor) {
            return $items;
        }

        $roles = $actor->roles();
        $teamIds = $roles->teamIds();
        if ([] !== $teamIds || $roles->isManager()) {
            $items[] = $this->pageItem('Tickets', self::TICKETS_ROUTE);
        }
        if ([] !== $teamIds) {
            $items[] = $this->pageItem('My reports', self::MY_REPORTS_ROUTE);
            $teamItem = $this->teamItem($actor, $teamIds);
            if (null !== $teamItem) {
                $items[] = $teamItem;
            }
        }
        if ($roles->isAdmin()) {
            $items[] = $this->pageItem('Admin', self::ADMIN_ROUTE);
        }

        return $items;
    }

    /**
     * True with any team role (lead, member or viewer); it decides both "Report a problem" and the bell (FR-ORG-05).
     */
    #[AsTwigFunction('can_report_problems')]
    public function canReportProblems(): bool
    {
        return [] !== ($this->actor()?->roles()->teamIds() ?? []);
    }

    private function actor(): ?ActorContext
    {
        return $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser());
    }

    /**
     * @param non-empty-list<TeamId> $teamIds
     *
     * @return MenuItem|null null when none of the teams is found, which happens only outside the request transaction
     */
    private function teamItem(ActorContext $actor, array $teamIds): ?MenuItem
    {
        if (1 === \count($teamIds)) {
            return $this->pageItem('Team', self::TEAM_ROUTE, ['teamId' => $teamIds[0]->toString()]);
        }

        $teamPages = array_map(
            fn (TeamSummary $team): MenuItem => $this->pageItem($team->name(), self::TEAM_ROUTE, ['teamId' => $team->id()->toString()]),
            $this->teamQueries->summariesOf($actor->organizationId(), $teamIds),
        );

        return [] === $teamPages ? null : MenuItem::group('Teams', $teamPages);
    }

    /**
     * @param array<string, string> $routeParameters
     */
    private function pageItem(string $label, string $routeName, array $routeParameters = []): MenuItem
    {
        try {
            $url = $this->urlGenerator->generate($routeName, $routeParameters);
        } catch (RouteNotFoundException) {
            $url = null;
        }

        return MenuItem::page($label, $routeName, $routeParameters, $url, $this->isCurrentPage($routeName, $routeParameters));
    }

    /**
     * @param array<string, string> $routeParameters
     */
    private function isCurrentPage(string $routeName, array $routeParameters): bool
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || $request->attributes->get('_route') !== $routeName) {
            return false;
        }

        $currentParameters = $request->attributes->all('_route_params');

        return array_all($routeParameters, static fn (string $value, string $name): bool => ($currentParameters[$name] ?? null) === $value);
    }
}
