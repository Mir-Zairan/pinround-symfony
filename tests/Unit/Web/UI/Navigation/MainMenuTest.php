<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Navigation;

use App\Insight\Api\MyReportsCounts;
use App\Insight\Api\MyReportsTotals;
use App\Insight\Api\TicketViewer;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\Organization\UI\Admin\AiSettingsController;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Web\UI\Navigation\MainMenu;
use App\Web\UI\Navigation\MenuItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Acme Foods has the teams Shop and Mobile, with the slugs shop and mobile-app, unless a test gives it others. Only the
 * routes a test adds exist, besides Home.
 */
final class MainMenuTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000000d2';
    private const string SUPPORT = '0197a3c0-0000-7000-8000-0000000000d3';
    private const array SLUGS = [self::SHOP => 'shop', self::MOBILE => 'mobile-app', self::SUPPORT => 'support'];

    private RouteCollection $routes;

    private RequestStack $requestStack;

    private TeamQueries&\Countable $teamQueries;

    protected function setUp(): void
    {
        $this->routes = new RouteCollection();
        $this->routes->add(MainMenu::HOME_ROUTE, new Route('/'));
        $this->requestStack = new RequestStack();
        $this->teamQueries = self::teamQueriesOf([self::SHOP => 'Shop', self::MOBILE => 'Mobile']);
    }

    /**
     * Acme Foods' teams as TeamQueries gives them, ordered by name; it counts its queries.
     *
     * @param array<string, string> $teamNamesById
     */
    private static function teamQueriesOf(array $teamNamesById): TeamQueries&\Countable
    {
        return new class($teamNamesById, self::SLUGS) implements TeamQueries, \Countable {
            /** @var int<0, max> */
            private int $queries = 0;

            /**
             * @param array<string, string> $teamNamesById
             * @param array<string, string> $teamSlugsById
             */
            public function __construct(private readonly array $teamNamesById, private readonly array $teamSlugsById)
            {
            }

            public function teamsOf(OrganizationId $organizationId): array
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function allSummariesOf(OrganizationId $organizationId): array
            {
                ++$this->queries;

                return $this->summariesByName(...array_map(static fn (string $teamId): TeamId => new TeamId($teamId), array_keys($this->teamNamesById)));
            }

            public function countOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function leadOf(TeamId $teamId): ?UserId
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function possibleAssigneesOf(array $teamIds): array
            {
                throw new \LogicException('Only summariesOf and allSummariesOf are expected.');
            }

            public function summariesOf(OrganizationId $organizationId, array $teamIds): array
            {
                ++$this->queries;

                return $this->summariesByName(...$teamIds);
            }

            /**
             * The number of summariesOf and allSummariesOf calls so far.
             */
            public function count(): int
            {
                return $this->queries;
            }

            /** @return list<TeamSummary> */
            private function summariesByName(TeamId ...$teamIds): array
            {
                $summaries = [];
                foreach ($teamIds as $teamId) {
                    if (isset($this->teamNamesById[$teamId->toString()])) {
                        $summaries[] = new TeamSummary($teamId, $this->teamNamesById[$teamId->toString()], $this->teamSlugsById[$teamId->toString()]);
                    }
                }
                usort($summaries, static fn (TeamSummary $first, TeamSummary $second): int => strcasecmp($first->name(), $second->name()));

                return $summaries;
            }
        };
    }

    #[Test]
    public function anItemWhoseRouteDoesNotExistYetHasNoUrl(): void
    {
        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame(['Home', 'Tickets', 'Teams'], self::labelsOf($items));
        self::assertSame(MainMenu::TICKETS_ROUTE, $items[1]->routeName());
        self::assertNull($items[1]->url());
        self::assertFalse($items[1]->isPageBuilt());
        self::assertSame([null, null], array_map(static fn (MenuItem $team): ?string => $team->url(), $items[2]->children()));
    }

    #[Test]
    public function anItemBecomesALinkOnceItsRouteExists(): void
    {
        $this->routes->add(MainMenu::TICKETS_ROUTE, new Route('/tickets'));

        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame('/tickets', $items[1]->url());
        self::assertTrue($items[1]->isPageBuilt());
        self::assertSame('/', $items[0]->url());
    }

    #[Test]
    public function theTeamItemLinksTheOnlyTeamByTheSlugInTheRoles(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(false, false, [], [new TeamId(self::SHOP)], [], [self::SHOP => 'shop']))->items();

        self::assertSame(['Home', 'Tickets', 'Team'], self::labelsOf($items));
        self::assertFalse($items[2]->isGroup());
        self::assertSame(['slug' => 'shop'], $items[2]->routeParameters());
        self::assertSame('/teams/shop', $items[2]->url());
        self::assertCount(0, $this->teamQueries, 'one team needs no names');
    }

    #[Test]
    public function theTeamItemIsLeftOutWhenTheRolesCarryNoSlugForTheOnlyTeam(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(false, false, [], [new TeamId(self::SHOP)], []))->items();

        self::assertSame(['Home', 'Tickets'], self::labelsOf($items));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function twoOrMoreTeamsBecomeATeamsGroupListedByName(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(true, false, [new TeamId(self::SHOP)], [], [new TeamId(self::MOBILE)]))->items();

        self::assertSame(['Home', 'Tickets', 'Teams', 'Admin'], self::labelsOf($items));
        $teams = $items[2];
        self::assertTrue($teams->isGroup());
        self::assertFalse($teams->isCurrent());
        self::assertNull($teams->url());
        self::assertSame(['Mobile', 'Shop'], self::labelsOf($teams->children()));
        self::assertSame(['/teams/mobile-app', '/teams/shop'], array_map(static fn (MenuItem $team): ?string => $team->url(), $teams->children()));
        self::assertCount(1, $this->teamQueries);
    }

    #[Test]
    public function theTeamsGroupIsLeftOutWhenNoTeamIsFound(): void
    {
        $unknownTeam = new TeamId('0197a3c0-0000-7000-8000-0000000000d9');
        $otherUnknownTeam = new TeamId('0197a3c0-0000-7000-8000-0000000000d8');

        $items = $this->menuFor(new Roles(false, false, [], [$unknownTeam, $otherUnknownTeam], []))->items();

        self::assertSame(['Home', 'Tickets'], self::labelsOf($items));
    }

    #[Test]
    public function aViewerCountsAsHavingATeamRole(): void
    {
        $menu = $this->menuFor(new Roles(false, false, [], [], [new TeamId(self::SHOP)], self::SLUGS));

        self::assertSame(['Home', 'Tickets', 'Team'], self::labelsOf($menu->items()));
        self::assertTrue($menu->canReportProblems());
    }

    /**
     * Hidden rather than inert until P14.T15 builds the page (product decision 2026-11-20).
     */
    #[Test]
    public function myReportsIsLeftOutUntilItsRouteExists(): void
    {
        $roles = new Roles(false, false, [], [new TeamId(self::SHOP)], [], self::SLUGS);
        self::assertNotContains('My reports', self::labelsOf($this->menuFor($roles)->items()));

        $this->routes->add(MainMenu::MY_REPORTS_ROUTE, new Route('/my-reports'));
        $items = $this->menuFor($roles)->items();

        self::assertSame(['Home', 'Tickets', 'My reports', 'Team'], self::labelsOf($items));
        self::assertSame('/my-reports', $items[2]->url());
    }

    #[Test]
    public function aManagerWithoutATeamGetsTicketsAndEveryTeamButNoReportButton(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $menu = $this->menuFor(new Roles(false, true, [], [], []));

        $items = $menu->items();

        self::assertSame(['Home', 'Tickets', 'Teams'], self::labelsOf($items));
        self::assertSame(['/teams/mobile-app', '/teams/shop'], array_map(static fn (MenuItem $team): ?string => $team->url(), $items[2]->children()));
        self::assertFalse($menu->canReportProblems());
    }

    /**
     * TeamVoter lets a Manager open every team of the organization, not only the ones they hold a role in.
     */
    #[Test]
    public function aManagerWhoViewsOneTeamGetsEveryTeamOfTheOrganizationInOneQuery(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(false, true, [], [], [new TeamId(self::SHOP)], [self::SHOP => 'shop']))->items();

        self::assertSame(['Home', 'Tickets', 'Teams'], self::labelsOf($items));
        self::assertTrue($items[2]->isGroup());
        self::assertSame(['Mobile', 'Shop'], self::labelsOf($items[2]->children()));
        self::assertSame(['/teams/mobile-app', '/teams/shop'], array_map(static fn (MenuItem $team): ?string => $team->url(), $items[2]->children()));
        self::assertCount(1, $this->teamQueries);
    }

    #[Test]
    public function aLeadWhoIsNoManagerGetsOnlyTheTeamsTheyHoldARoleIn(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $this->teamQueries = self::teamQueriesOf([self::SHOP => 'Shop', self::MOBILE => 'Mobile', self::SUPPORT => 'Support']);

        $items = $this->menuFor(new Roles(true, false, [new TeamId(self::SHOP)], [new TeamId(self::MOBILE)], []))->items();

        self::assertSame(['Mobile', 'Shop'], self::labelsOf($items[2]->children()), 'Support is a team of the organization they hold no role in');
    }

    #[Test]
    public function aManagerOfAnOrganizationWithOneTeamGetsTeam(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $this->teamQueries = self::teamQueriesOf([self::MOBILE => 'Mobile']);

        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame(['Home', 'Tickets', 'Team'], self::labelsOf($items));
        self::assertFalse($items[2]->isGroup());
        self::assertSame('/teams/mobile-app', $items[2]->url());
    }

    #[Test]
    public function aManagerOfAnOrganizationWithoutTeamsGetsNoTeamItem(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $this->teamQueries = self::teamQueriesOf([]);

        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame(['Home', 'Tickets'], self::labelsOf($items));
        self::assertCount(1, $this->teamQueries);
    }

    #[Test]
    public function aPersonWithoutATeamOrTheManagerRoleRunsNoTeamQuery(): void
    {
        $items = $this->menuFor(new Roles(true, false, [], [], []))->items();

        self::assertSame(['Home', 'Admin'], self::labelsOf($items));
        self::assertCount(0, $this->teamQueries, 'an Admin who is not a Manager gets no team list here');
    }

    #[Test]
    public function anAdminWithoutATeamGetsHomeAndAdminOnly(): void
    {
        $menu = $this->menuFor(new Roles(true, false, [], [], []));

        self::assertSame(['Home', 'Admin'], self::labelsOf($menu->items()));
        self::assertSame(MainMenu::ADMIN_ROUTE, $menu->items()[1]->routeName());
        self::assertFalse($menu->canReportProblems());
    }

    #[Test]
    public function adminLinksTheAdminArea(): void
    {
        $this->routes->add(MainMenu::ADMIN_ROUTE, new Route('/admin'));

        $admin = $this->menuFor(new Roles(true, false, [], [], []))->items()[1];

        self::assertSame('/admin', $admin->url());
        self::assertSame([], $admin->routeParameters());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function routesAndWhetherAdminIsCurrent(): iterable
    {
        yield 'the admin area' => [MainMenu::ADMIN_ROUTE, true];
        yield 'an admin tab of another module' => [AiSettingsController::ROUTE, true];
        yield 'a form of an admin tab' => [PeopleController::INVITE_ROUTE, true];
        yield 'a route under the admin area address that is no tab' => [MainMenu::ADMIN_ROUTE.'_ai', false];
        yield 'a route that only starts with the same letters as the address' => [MainMenu::ADMIN_ROUTE.'s', false];
        yield 'a route that only starts with the same letters as a tab' => [PeopleController::ROUTE.'s', false];
        yield 'Home' => [MainMenu::HOME_ROUTE, false];
    }

    #[Test]
    #[DataProvider('routesAndWhetherAdminIsCurrent')]
    public function adminIsCurrentOnTheAdminAreaAndEveryRouteUnderIt(string $currentRoute, bool $isCurrent): void
    {
        $this->routes->add(MainMenu::ADMIN_ROUTE, new Route('/admin'));
        $this->requestStack->push(new Request(attributes: ['_route' => $currentRoute, '_route_params' => []]));

        $admin = $this->menuFor(new Roles(true, false, [], [], []))->items()[1];

        self::assertSame($isCurrent, $admin->isCurrent());
    }

    #[Test]
    public function theAdminHomeUrlIsNullWhileTheAdminRouteDoesNotExist(): void
    {
        self::assertNull($this->menuFor(new Roles(true, false, [], [], []))->adminHomeUrl());
    }

    #[Test]
    public function theAdminHomeUrlIsTheSameAsTheAdminItemsOnceTheRouteExists(): void
    {
        $this->routes->add(MainMenu::ADMIN_ROUTE, new Route('/admin'));
        $menu = $this->menuFor(new Roles(true, false, [], [], []));

        self::assertSame('/admin', $menu->adminHomeUrl());
        self::assertSame($menu->items()[1]->url(), $menu->adminHomeUrl());
    }

    #[Test]
    public function withoutAnActorTheMenuIsHomeAloneAndThereIsNoReportButton(): void
    {
        $menu = $this->menuWithoutActor();

        self::assertSame(['Home'], self::labelsOf($menu->items()));
        self::assertFalse($menu->canReportProblems());
    }

    #[Test]
    public function theItemOfTheCurrentRouteIsCurrent(): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => MainMenu::HOME_ROUTE, '_route_params' => []]));

        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertTrue($items[0]->isCurrent());
        self::assertFalse($items[1]->isCurrent());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function routesAndWhetherTicketsIsCurrent(): iterable
    {
        yield 'the tickets list' => [MainMenu::TICKETS_ROUTE, true];
        yield 'Whose turn?' => ['ticketing_whose_turn', true];
        yield 'a ticket page' => ['ticket_show', true];
        yield 'a route that only starts with the same letters as the list' => [MainMenu::TICKETS_ROUTE.'_frame', false];
        yield 'Report a problem' => ['ticketing_report', false];
        yield 'Home' => [MainMenu::HOME_ROUTE, false];
    }

    #[Test]
    #[DataProvider('routesAndWhetherTicketsIsCurrent')]
    public function ticketsIsCurrentOnTheListWhoseTurnAndATicketPage(string $currentRoute, bool $isCurrent): void
    {
        $this->requestStack->push(new Request(attributes: ['_route' => $currentRoute, '_route_params' => ['key' => 'SHOP-1']]));

        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame('Tickets', $items[1]->label());
        self::assertSame($isCurrent, $items[1]->isCurrent());
        self::assertSame(MainMenu::HOME_ROUTE === $currentRoute, $items[0]->isCurrent());
    }

    #[Test]
    public function onlyTheTeamOfTheCurrentTeamPageIsCurrent(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $this->requestStack->push(new Request(attributes: ['_route' => MainMenu::TEAM_ROUTE, '_route_params' => ['slug' => 'shop']]));

        $items = $this->menuFor(new Roles(false, false, [], [new TeamId(self::SHOP), new TeamId(self::MOBILE)], []))->items();

        self::assertFalse($items[0]->isCurrent());
        self::assertTrue($items[2]->isCurrent(), 'the Teams group is current while one of its teams is');
        self::assertSame(['Mobile' => false, 'Shop' => true], array_combine(
            self::labelsOf($items[2]->children()),
            array_map(static fn (MenuItem $team): bool => $team->isCurrent(), $items[2]->children()),
        ));
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<string>
     */
    private static function labelsOf(array $items): array
    {
        return array_map(static fn (MenuItem $item): string => $item->label(), $items);
    }

    private function menuFor(Roles $roles): MainMenu
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(self::LENA, null), 'main'));
        $actor = new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), $roles);

        return new MainMenu($tokenStorage, new FixedCurrentActor([self::LENA => $actor]), $this->teamQueries, self::noQuestionsWaiting(), $this->urlGenerator(), $this->requestStack);
    }

    private function menuWithoutActor(): MainMenu
    {
        return new MainMenu(new TokenStorage(), new FixedCurrentActor([]), $this->teamQueries, self::noQuestionsWaiting(), $this->urlGenerator(), $this->requestStack);
    }

    private static function noQuestionsWaiting(): MyReportsCounts
    {
        return new class implements MyReportsCounts {
            public function questionsWaitingOn(OrganizationId $organizationId, UserId $reporterId): int
            {
                return 0;
            }

            public function totalsOf(TicketViewer $viewer): MyReportsTotals
            {
                throw new \LogicException('The menu reads only the badge count.');
            }
        };
    }

    private function urlGenerator(): UrlGenerator
    {
        return new UrlGenerator($this->routes, new RequestContext());
    }
}
