<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Navigation;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Web\UI\Navigation\MainMenu;
use App\Web\UI\Navigation\MenuItem;
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
 * Acme Foods has the teams Shop and Mobile, with the slugs shop and mobile-app. Only the routes a test adds exist,
 * besides Home.
 */
final class MainMenuTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000000d2';
    private const array SLUGS = [self::SHOP => 'shop', self::MOBILE => 'mobile-app'];

    private RouteCollection $routes;

    private RequestStack $requestStack;

    private TeamQueries&\Countable $teamQueries;

    protected function setUp(): void
    {
        $this->routes = new RouteCollection();
        $this->routes->add(MainMenu::HOME_ROUTE, new Route('/'));
        $this->requestStack = new RequestStack();
        $this->teamQueries = new class([self::SHOP => 'Shop', self::MOBILE => 'Mobile'], self::SLUGS) implements TeamQueries, \Countable {
            /** @var int<0, max> */
            private int $summariesOfCalls = 0;

            /**
             * @param array<string, string> $teamNamesById
             * @param array<string, string> $teamSlugsById
             */
            public function __construct(private readonly array $teamNamesById, private readonly array $teamSlugsById)
            {
            }

            public function teamsOf(OrganizationId $organizationId): array
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function allSummariesOf(OrganizationId $organizationId): array
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function countOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function leadOf(TeamId $teamId): ?UserId
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function peopleByJob(TeamId $teamId): array
            {
                throw new \LogicException('Only summariesOf is expected.');
            }

            public function summariesOf(OrganizationId $organizationId, array $teamIds): array
            {
                ++$this->summariesOfCalls;
                $summaries = [];
                foreach ($teamIds as $teamId) {
                    if (isset($this->teamNamesById[$teamId->toString()])) {
                        $summaries[] = new TeamSummary($teamId, $this->teamNamesById[$teamId->toString()], $this->teamSlugsById[$teamId->toString()]);
                    }
                }
                usort($summaries, static fn (TeamSummary $first, TeamSummary $second): int => strcasecmp($first->name(), $second->name()));

                return $summaries;
            }

            /**
             * The number of summariesOf calls so far.
             */
            public function count(): int
            {
                return $this->summariesOfCalls;
            }
        };
    }

    #[Test]
    public function anItemWhoseRouteDoesNotExistYetHasNoUrl(): void
    {
        $items = $this->menuFor(new Roles(false, true, [], [], []))->items();

        self::assertSame(['Home', 'Tickets'], self::labelsOf($items));
        self::assertSame(MainMenu::TICKETS_ROUTE, $items[1]->routeName());
        self::assertNull($items[1]->url());
        self::assertFalse($items[1]->isPageBuilt());
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

        self::assertSame(['Home', 'Tickets', 'My reports', 'Team'], self::labelsOf($items));
        self::assertFalse($items[3]->isGroup());
        self::assertSame(['slug' => 'shop'], $items[3]->routeParameters());
        self::assertSame('/teams/shop', $items[3]->url());
        self::assertCount(0, $this->teamQueries, 'one team needs no names');
    }

    #[Test]
    public function theTeamItemIsLeftOutWhenTheRolesCarryNoSlugForTheOnlyTeam(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(false, false, [], [new TeamId(self::SHOP)], []))->items();

        self::assertSame(['Home', 'Tickets', 'My reports'], self::labelsOf($items));
        self::assertCount(0, $this->teamQueries);
    }

    #[Test]
    public function twoOrMoreTeamsBecomeATeamsGroupListedByName(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));

        $items = $this->menuFor(new Roles(true, false, [new TeamId(self::SHOP)], [], [new TeamId(self::MOBILE)]))->items();

        self::assertSame(['Home', 'Tickets', 'My reports', 'Teams', 'Admin'], self::labelsOf($items));
        $teams = $items[3];
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

        self::assertSame(['Home', 'Tickets', 'My reports'], self::labelsOf($items));
    }

    #[Test]
    public function aViewerCountsAsHavingATeamRole(): void
    {
        $menu = $this->menuFor(new Roles(false, false, [], [], [new TeamId(self::SHOP)], self::SLUGS));

        self::assertSame(['Home', 'Tickets', 'My reports', 'Team'], self::labelsOf($menu->items()));
        self::assertTrue($menu->canReportProblems());
    }

    #[Test]
    public function aManagerWithoutATeamGetsTicketsButNoReportButton(): void
    {
        $menu = $this->menuFor(new Roles(false, true, [], [], []));

        self::assertSame(['Home', 'Tickets'], self::labelsOf($menu->items()));
        self::assertFalse($menu->canReportProblems());
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
    public function adminLinksThePeopleTabAndIsCurrentOnEveryAdminTab(): void
    {
        $this->routes->add(MainMenu::ADMIN_ROUTE, new Route('/admin/{tab}'));
        $this->requestStack->push(new Request(attributes: ['_route' => MainMenu::ADMIN_ROUTE, '_route_params' => ['tab' => 'teams']]));

        $admin = $this->menuFor(new Roles(true, false, [], [], []))->items()[1];

        self::assertSame('/admin/people', $admin->url());
        self::assertTrue($admin->isCurrent());
    }

    #[Test]
    public function theAdminHomeUrlIsNullWhileTheAdminRouteDoesNotExist(): void
    {
        self::assertNull($this->menuFor(new Roles(true, false, [], [], []))->adminHomeUrl());
    }

    #[Test]
    public function theAdminHomeUrlIsTheSameAsTheAdminItemsOnceTheRouteExists(): void
    {
        $this->routes->add(MainMenu::ADMIN_ROUTE, new Route('/admin/{tab}'));
        $menu = $this->menuFor(new Roles(true, false, [], [], []));

        self::assertSame('/admin/people', $menu->adminHomeUrl());
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

    #[Test]
    public function onlyTheTeamOfTheCurrentTeamPageIsCurrent(): void
    {
        $this->routes->add(MainMenu::TEAM_ROUTE, new Route('/teams/{slug}'));
        $this->requestStack->push(new Request(attributes: ['_route' => MainMenu::TEAM_ROUTE, '_route_params' => ['slug' => 'shop']]));

        $items = $this->menuFor(new Roles(false, false, [], [new TeamId(self::SHOP), new TeamId(self::MOBILE)], []))->items();

        self::assertFalse($items[0]->isCurrent());
        self::assertTrue($items[3]->isCurrent(), 'the Teams group is current while one of its teams is');
        self::assertSame(['Mobile' => false, 'Shop' => true], array_combine(
            self::labelsOf($items[3]->children()),
            array_map(static fn (MenuItem $team): bool => $team->isCurrent(), $items[3]->children()),
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

        return new MainMenu($tokenStorage, new FixedCurrentActor([self::LENA => $actor]), $this->teamQueries, $this->urlGenerator(), $this->requestStack);
    }

    private function menuWithoutActor(): MainMenu
    {
        return new MainMenu(new TokenStorage(), new FixedCurrentActor([]), $this->teamQueries, $this->urlGenerator(), $this->requestStack);
    }

    private function urlGenerator(): UrlGenerator
    {
        return new UrlGenerator($this->routes, new RequestContext());
    }
}
