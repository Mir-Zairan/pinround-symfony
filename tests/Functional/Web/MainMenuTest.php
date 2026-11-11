<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Ticketing\UI\TicketListController;
use App\Web\UI\Controller\TeamController;
use App\Web\UI\Navigation\MainMenu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The header's main menu, "Report a problem" and the bell for each person of Acme Foods, which has the teams Shop and
 * Mobile, both led by Lena. Team links the team page by its slug and Tickets the tickets list;
 * My reports and Admin have no route yet, so they are inert.
 */
final class MainMenuTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

    private TeamId $shop;

    private TeamId $mobile;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, bool}> the person, their menu items, the teams
     *                                                                           under "Teams", and whether they get
     *                                                                           "Report a problem" and the bell
     */
    public static function people(): iterable
    {
        yield 'Lena, Admin and lead of Shop and Mobile' => ['Lena', ['Home', 'Tickets', 'My reports', 'Teams', 'Admin'], ['Mobile', 'Shop'], true];
        yield 'Omar, member of Shop and Mobile' => ['Omar', ['Home', 'Tickets', 'My reports', 'Teams'], ['Mobile', 'Shop'], true];
        yield 'Mia, member of Shop' => ['Mia', ['Home', 'Tickets', 'My reports', 'Team'], [], true];
        yield 'Raj, Manager and viewer of Shop' => ['Raj', ['Home', 'Tickets', 'My reports', 'Team'], [], true];
        yield 'Sara, Admin with no team' => ['Sara', ['Home', 'Admin'], [], false];
        yield 'Noor, Manager with no team' => ['Noor', ['Home', 'Tickets'], [], false];
    }

    /**
     * @param list<string> $expectedItems
     * @param list<string> $expectedTeams
     */
    #[Test]
    #[DataProvider('people')]
    public function eachPersonSeesTheItemsOfTheirRolesInOrder(string $person, array $expectedItems, array $expectedTeams, bool $canReportProblems): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $menu = $page->filter('header nav[aria-label="Main"]');
        self::assertSame($expectedItems, $menu->children()->each(static fn (Crawler $item): string => self::labelOf($item)));
        self::assertSame($expectedTeams, $menu->filter('.nav-menu__list > *')->each(static fn (Crawler $team): string => self::labelOf($team)));
        self::assertSame($canReportProblems ? ['Report a problem'] : [], $page->filter('header button.btn-tonal')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount($canReportProblems ? 1 : 0, $page->filter('header button.icon-btn[aria-label="Pings"]'));
    }

    #[Test]
    public function anItemWithARouteIsALinkAndOneWithoutIsInertTextThatSaysSo(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $menu = $page->filter('header nav[aria-label="Main"]');
        self::assertSame(
            ['Home → / (current)', 'Tickets → /tickets', 'Mobile → /teams/mobile', 'Shop → /teams/shop'],
            $menu->filter('a')->each(static fn (Crawler $link): string => $link->text().' → '.$link->attr('href').('page' === $link->attr('aria-current') ? ' (current)' : '')),
        );
        self::assertCount(2, $menu->filter('.nav-menu__list > a.menu__item[data-menu-target="item"]'), 'the team links are the disclosure\'s items');

        $notBuilt = $menu->filter('span.nav--not-built, span.menu__item--not-built');
        self::assertSame(['My reports (not built yet)', 'Admin (not built yet)'], $notBuilt->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame([' (not built yet)'], array_unique($notBuilt->filter('.visually-hidden')->each(static fn (Crawler $hidden): string => $hidden->text(normalizeWhitespace: false))));
        self::assertCount(0, $menu->filter('span[href], [tabindex]'));

        $teamsButton = $menu->filter('div.nav-menu > button.nav[aria-expanded="false"]');
        self::assertCount(1, $teamsButton);
        self::assertSame('nav', $teamsButton->attr('class'));
        self::assertCount(1, $menu->filter('#'.$teamsButton->attr('aria-controls').'.nav-menu__list[hidden]'));
        self::assertCount(0, $page->filterXPath('//header//*[@style or @*[starts-with(name(), "on")]]'), 'the CSP blocks inline styles and handlers');
    }

    #[Test]
    public function onATeamPageThatTeamIsTheCurrentPageAndTheTeamsButtonLooksCurrent(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        $menu = $page->filter('header nav[aria-label="Main"]');
        self::assertSame(['Shop'], $menu->filter('a[aria-current="page"]')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('nav nav--current', $menu->filter('div.nav-menu > button')->attr('class'));
    }

    #[Test]
    public function theOnlyTeamOfAPersonIsLinkedAsTeam(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Mia']);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/teams/shop', $page->filter('header nav[aria-label="Main"] > a.nav')->reduce(static fn (Crawler $link): bool => 'Team' === $link->text())->attr('href'));
    }

    #[Test]
    public function theMenuRoutesAreTheRealPages(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();

        // RequestTransactionTest proves test_fixtures.yaml defines no route of this name, in any environment.
        $ticketsRoute = $routes->get(MainMenu::TICKETS_ROUTE);
        self::assertNotNull($ticketsRoute);
        self::assertSame(TicketListController::class.'::list', $ticketsRoute->getDefault('_controller'), 'the tickets list is the real one, not a stand-in');
        self::assertSame('/tickets', $ticketsRoute->getPath());
        self::assertSame(TeamController::class.'::show', $routes->get(MainMenu::TEAM_ROUTE)?->getDefault('_controller'), 'the team page is the real one, not a stand-in');
        foreach ([MainMenu::MY_REPORTS_ROUTE, MainMenu::ADMIN_ROUTE] as $routeName) {
            self::assertNull($routes->get($routeName), $routeName.' has a page now: MainMenuTest expects it inert');
        }
    }

    #[Test]
    public function onThePickerAPersonWithATeamRoleSeesHomeAloneAndNoReportButton(): void
    {
        $omar = $this->createAcmeFoodsPeople()['Omar'];
        $this->addMembership($omar, $this->createOrganization('Beta Bakery'));
        $this->logIn($this->client, $omar);

        $page = $this->client->request('GET', '/org/choose');

        self::assertResponseStatusCodeSame(200);
        $menu = $page->filter('header nav[aria-label="Main"]');
        self::assertSame(['Home'], $menu->children()->each(static fn (Crawler $item): string => self::labelOf($item)));
        self::assertNull($menu->filter('a.nav')->attr('aria-current'));
        self::assertCount(0, $page->filter('header button.btn-tonal'));
        self::assertCount(0, $page->filter('header button[aria-label="Pings"]'));
    }

    /**
     * The label without the hidden "(not built yet)": the first text of the item, or of the button of a group.
     */
    private static function labelOf(Crawler $item): string
    {
        $labelHolder = 'div' === $item->nodeName() ? $item->filter('button') : $item;

        return trim((string) $labelHolder->getNode(0)?->firstChild?->textContent);
    }

    /**
     * @return array<string, User> by first name
     */
    private function createAcmeFoodsPeople(): array
    {
        $acmeFoods = $this->createOrganization('Acme Foods');
        $people = [];
        foreach (['Lena' => '{admin}', 'Omar' => '{}', 'Mia' => '{}', 'Raj' => '{manager}', 'Sara' => '{admin}', 'Noor' => '{manager}'] as $name => $orgRoles) {
            $people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($people[$name], $acmeFoods);
            $this->setOrgRoles($people[$name], $acmeFoods, $orgRoles);
        }

        $this->shop = $this->createTeam($acmeFoods, 'Shop', $people['Lena']);
        $this->mobile = $this->createTeam($acmeFoods, 'Mobile', $people['Lena']);
        $this->addTeamRole($people['Omar'], $acmeFoods, $this->shop, 'member');
        $this->addTeamRole($people['Omar'], $acmeFoods, $this->mobile, 'member');
        $this->addTeamRole($people['Mia'], $acmeFoods, $this->shop, 'member');
        $this->addTeamRole($people['Raj'], $acmeFoods, $this->shop, 'viewer');

        return $people;
    }
}
