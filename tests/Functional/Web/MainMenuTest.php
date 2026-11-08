<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The header's main menu, "Report a problem" and the bell for each person of Acme Foods, which has the teams Shop and
 * Mobile, both led by Lena. No phase has built the pages behind Tickets, My reports, Team and Admin yet, so those items
 * are inert; tests/Unit/Web/UI/Navigation/MainMenuTest covers them as links.
 */
final class MainMenuTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

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
    public function anItemWhosePageIsNotBuiltIsInertTextThatSaysSo(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $menu = $page->filter('header nav[aria-label="Main"]');
        $home = $menu->filter('a.nav');
        self::assertSame(['Home'], $home->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('/', $home->attr('href'));
        self::assertSame('page', $home->attr('aria-current'));

        $notBuilt = $menu->filter('span.nav--not-built, span.menu__item--not-built');
        self::assertSame(
            ['Tickets (not built yet)', 'My reports (not built yet)', 'Mobile (not built yet)', 'Shop (not built yet)', 'Admin (not built yet)'],
            $notBuilt->each(static fn (Crawler $item): string => $item->text()),
        );
        self::assertSame([' (not built yet)'], array_unique($notBuilt->filter('.visually-hidden')->each(static fn (Crawler $hidden): string => $hidden->text(normalizeWhitespace: false))));
        self::assertCount(0, $menu->filter('[href]:not(a.nav), [tabindex]'));

        $teamsButton = $menu->filter('div.nav-menu > button.nav[aria-expanded="false"]');
        self::assertCount(1, $teamsButton);
        self::assertCount(1, $menu->filter('#'.$teamsButton->attr('aria-controls').'.nav-menu__list[hidden]'));
        self::assertCount(0, $page->filterXPath('//header//*[@style or @*[starts-with(name(), "on")]]'), 'the CSP blocks inline styles and handlers');
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

        $shop = $this->createTeam($acmeFoods, 'Shop', $people['Lena']);
        $mobile = $this->createTeam($acmeFoods, 'Mobile', $people['Lena']);
        $this->addTeamRole($people['Omar'], $acmeFoods, $shop, 'member');
        $this->addTeamRole($people['Omar'], $acmeFoods, $mobile, 'member');
        $this->addTeamRole($people['Mia'], $acmeFoods, $shop, 'member');
        $this->addTeamRole($people['Raj'], $acmeFoods, $shop, 'viewer');

        return $people;
    }
}
