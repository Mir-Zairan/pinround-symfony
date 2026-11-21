<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET / (web_home) for each person of Acme Foods, which has the teams Shop and Mobile, both led by Lena: which of the
 * three Homes opens, its chips and its sections.
 */
final class HomePageTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string LEAD_LINE = 'You lead the team, so reviews, decisions and unclear new tickets come to you.';
    private const string NOW = '2026-11-05 12:00:00 UTC';

    private KernelBrowser $client;

    private OrganizationId $acmeFoods;

    /** @var array<string, User> by first name */
    private array $people = [];

    protected function setUp(): void
    {
        $this->client = self::httpsClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM web_home_focus WHERE organization_id = ?',
                [$organizationId],
            ));
        }
        $this->ticketRows()->removeAllOf(...$this->createdOrganizationIds);
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function lenaOpensTheWorkHomeWithHerFourChipsAndAllSectionsShown(): void
    {
        $page = $this->openHomeAs('Lena');

        self::assertSame('All clear, Lena', $page->filter('h1')->text());
        self::assertSame(self::LEAD_LINE, $page->filter('.home__subline')->text());
        self::assertSame(['All', 'My work', 'My team', 'Admin'], $this->chipLabels($page));
        self::assertSame(['All'], $this->pressedChipLabels($page));
        self::assertSame(
            ['Needs you now', 'Also waiting on you', 'Admin', 'Release', 'Your team'],
            $page->filter('section.home__section h2')->each(static fn (Crawler $title): string => $title->text()),
        );
        self::assertCount(0, $page->filter('section.home__section[hidden]'));
        self::assertSame('Nothing needs you as admin.', $page->filter('section[data-focus-group="admin"] .empty-state')->text());
        self::assertSame('Show on Home', $page->filter('.home__chips')->attr('aria-label'));
        self::assertSame('group', $page->filter('.home__chips')->attr('role'));
    }

    #[Test]
    public function aChipIsASubmitButtonOfAFormThatPostsTheFocusWithItsCsrfToken(): void
    {
        $page = $this->openHomeAs('Lena');

        $form = $page->filter('form[action="/home/focus"][method="post"]');
        self::assertCount(1, $form);
        self::assertNotSame('', $form->filter('input[type="hidden"][name="_csrf_token"]')->attr('value'));
        self::assertSame(
            ['all', 'my_work', 'my_team', 'admin'],
            $form->filter('button.chip[type="submit"][name="focus"]')->each(static fn (Crawler $chip): string => (string) $chip->attr('value')),
        );
        self::assertSame('focus-chips', $page->filter('.home')->attr('data-controller'));
    }

    #[Test]
    public function omarSeesThreeChipsAndNeitherTheAdminNorTheReleaseNorTheAllTeamsSection(): void
    {
        $page = $this->openHomeAs('Omar');

        self::assertSame(['All', 'My work', 'My team'], $this->chipLabels($page));
        self::assertSame(
            ['Needs you now', 'Also waiting on you', 'Your team'],
            $page->filter('section.home__section h2')->each(static fn (Crawler $title): string => $title->text()),
        );
        self::assertCount(0, $page->filter('.home__subline'), 'the job of a member is not known to Home yet');
    }

    /**
     * Omar's bell holds an open ping from Lena; Home has no Pings for you section until P07.T2 reads them, so it never
     * says there are none.
     */
    #[Test]
    public function aPersonWithAnOpenPingIsNeverToldThereAreNone(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->addPingToOmarFromLena();

        $page = $this->openHomeAs('Omar');

        self::assertSame('1 open', $page->filter('#bell-badge .visually-hidden')->text(), 'the bell holds the ping');
        self::assertStringNotContainsString('No open pings', $page->filter('main')->text());
        self::assertSame(['Needs you now', 'Also waiting on you'], $page->filter('section[data-focus-group="my_work"] h2')->each(static fn (Crawler $title): string => $title->text()));
    }

    #[Test]
    public function aPersonWithNoPingIsNotToldAboutPingsOnHomeEither(): void
    {
        $page = $this->openHomeAs('Omar');

        self::assertCount(0, $page->filter('#bell-badge .bell__badge'), 'the bell holds no ping');
        self::assertStringNotContainsString('pings', strtolower($page->filter('main')->text()));
        self::assertSame(['Needs you now', 'Also waiting on you'], $page->filter('section[data-focus-group="my_work"] h2')->each(static fn (Crawler $title): string => $title->text()));
    }

    #[Test]
    public function aMemberWhoIsAlsoAManagerKeepsTheWorkHomeAndGetsTheAllTeamsChipAndSection(): void
    {
        $this->setOrgRoles($this->person('Mia'), $this->acmeFoods(), '{manager}');

        $page = $this->openHomeAs('Mia');

        self::assertSame('All clear, Mia', $page->filter('h1')->text());
        self::assertSame(['All', 'My work', 'My team', 'All teams'], $this->chipLabels($page));
        self::assertCount(1, $page->filter('section[data-focus-group="all_teams"]'));
        self::assertCount(0, $page->filter('.home__column section[data-focus-group="all_teams"]'), 'it sits below the two columns');
    }

    #[Test]
    public function aSavedChipIsPressedAndItsOtherSectionsAreHiddenAfterANewLogin(): void
    {
        $page = $this->openHomeAs('Lena');
        $client = $this->client;
        $client->submit($page->selectButton('My team')->form());
        self::assertResponseRedirects('/', 303);

        self::ensureKernelShutdown();
        $this->client = self::httpsClient();
        $page = $this->openHomeAs('Lena');

        self::assertSame(['My team'], $this->pressedChipLabels($page));
        $myWork = $page->filter('section[data-focus-group="my_work"]');
        self::assertCount(2, $myWork);
        self::assertCount(2, $page->filter('section[data-focus-group="my_work"][hidden]'));
        self::assertCount(1, $page->filter('section[data-focus-group="admin"][hidden]'));
        $myTeam = $page->filter('section[data-focus-group="my_team"]');
        self::assertCount(2, $myTeam);
        self::assertCount(0, $page->filter('section[data-focus-group="my_team"][hidden]'));
    }

    #[Test]
    public function aSavedAdminChipFallsBackToAllWhenTheAdminRoleIsLost(): void
    {
        $page = $this->openHomeAs('Lena');
        $this->client->submit($page->selectButton('Admin')->form());
        self::assertResponseRedirects('/', 303);
        $this->setOrgRoles($this->person('Lena'), $this->acmeFoods(), '{}');

        $page = $this->openHomeAs('Lena');

        self::assertSame(['All', 'My work', 'My team'], $this->chipLabels($page));
        self::assertSame(['All'], $this->pressedChipLabels($page));
        self::assertCount(0, $page->filter('section[hidden]'));
    }

    #[Test]
    public function theDateLineComesFromTheInjectedClock(): void
    {
        $this->client->disableReboot();
        // A fixed day well before today, so only the injected clock can give this date.
        $clock = new MockClock(new \DateTimeImmutable('2026-11-05 12:00:00 UTC'));
        self::getContainer()->set('clock', $clock);
        $this->logIn($this->client, $this->person('Lena'));

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Thursday, 5 November', $page->filter('.home__date')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homesWithADateLine(): iterable
    {
        yield 'the work Home' => ['Lena'];
        yield 'Teams at a glance' => ['Noor'];
    }

    #[Test]
    #[DataProvider('homesWithADateLine')]
    public function theDateLineIsTodayInTheTimeZoneTheUserSaved(string $person): void
    {
        $this->client->disableReboot();
        // 22:00 UTC on the 5th is already the 6th in Tokyo.
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('2026-11-05 22:00:00 UTC')));
        $user = $this->person($person);
        $this->migrator()->executeStatement("UPDATE idn_user SET time_zone = 'Asia/Tokyo' WHERE id = ?", [$user->id()->toString()]);
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();
        $this->logIn($this->client, $user);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Friday, 6 November', $page->filter('.home__date')->text());
    }

    #[Test]
    public function rajWhoIsManagerAndViewerOpensTeamsAtAGlanceWithACardPerTeamAndNoChips(): void
    {
        $page = $this->openHomeAs('Raj');

        self::assertSame('Teams at a glance', $page->filter('h1')->text());
        self::assertSame(
            'Every team uses the same five groups, even when its own steps differ. Open a team to see its steps.',
            $page->filter('.home__subline')->text(),
        );
        self::assertCount(0, $page->filter('.home__chips, button.chip'));
        self::assertSame(['Mobile', 'Shop'], $page->filter('article.card > h2')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(
            ['Mobile → /teams/mobile', 'Shop → /teams/shop'],
            $page->filter('article.card > h2 > a')->each(static fn (Crawler $link): string => $link->text().' → '.$link->attr('href')),
            'each name opens its team page',
        );
        self::assertCount(2, $page->filter('article.card .empty-state'));
        self::assertCount(1, $page->filter('.home__date'));
    }

    #[Test]
    public function aManagerInAnOrganizationWithoutTeamsIsToldToAskAnAdmin(): void
    {
        $manager = $this->createUserWithPassword('Noor');
        $this->setOrgRoles($manager, $this->onlyOrganization(), '{manager}');
        $this->logIn($this->client, $manager);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Teams at a glance', $page->filter('h1')->text());
        self::assertSame('No teams yet. An admin creates teams under Admin → Teams.', $page->filter('.empty-state')->text());
        self::assertCount(0, $page->filter('article.card'));
    }

    #[Test]
    public function aManagerWhoIsAlsoAdminStillGetsTheManagerHome(): void
    {
        $this->setOrgRoles($this->person('Noor'), $this->acmeFoods(), '{admin,manager}');

        $page = $this->openHomeAs('Noor');

        self::assertSame('Teams at a glance', $page->filter('h1')->text());
        self::assertSame(['Home', 'Tickets', 'Teams', 'Admin'], $this->menuLabels($page));
    }

    #[Test]
    public function saraWhoIsOnlyAdminOpensYourOrganizationWithOnlyHomeAndAdminInTheMenu(): void
    {
        $page = $this->openHomeAs('Sara');

        self::assertSame('Your organization', $page->filter('h1')->text());
        self::assertCount(0, $page->filter('.home__date, .home__chips, button.chip'));
        self::assertSame(['Home', 'Admin'], $this->menuLabels($page));
        self::assertSame(
            ['People: 6', 'Teams: 2', 'AI help: Off'],
            $page->filter('.home__stat')->each(static fn (Crawler $card): string => $card->filter('.home__stat-label')->text().': '.$card->filter('.home__stat-value')->text()),
        );
        self::assertSame('Admins do not work on tickets. Everything you set is under Admin.', $page->filter('main p')->text());
    }

    /**
     * Teams has no admin tab yet, so its tile links nowhere.
     */
    #[Test]
    public function thePeopleAndAiHelpTilesOpenTheirAdminTabs(): void
    {
        $page = $this->openHomeAs('Sara');

        self::assertSame(
            ['People' => '/admin/people', 'AI help' => '/admin/ai'],
            array_combine(
                $page->filter('.home__stats a.home__stat .home__stat-label')->each(static fn (Crawler $label): string => $label->text()),
                $page->filter('.home__stats a.home__stat')->each(static fn (Crawler $tile): string => (string) $tile->attr('href')),
            ),
        );
        self::assertCount(3, $page->filter('.home__stats .home__stat'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoAreNotOnlyAdmins(): iterable
    {
        yield 'a member' => ['Omar'];
        yield 'a lead who is also admin' => ['Lena'];
        yield 'a manager and viewer' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoAreNotOnlyAdmins')]
    public function onlyTheAdminHomeShowsTheOrganizationTiles(string $person): void
    {
        $page = $this->openHomeAs($person);

        self::assertCount(1, $page->filter('main h1'));
        self::assertCount(0, $page->filter('.home__stats, .home__stat'));
    }

    #[Test]
    public function openAdminSettingsLinksTheAdminArea(): void
    {
        $page = $this->openHomeAs('Sara');

        $link = $page->filter('main a.btn-filled');
        self::assertCount(1, $link);
        self::assertSame('Open admin settings', $link->text());
        self::assertSame('/admin', $link->attr('href'));
        self::assertSame($page->filter('header nav[aria-label="Main"] a')->reduce(static fn (Crawler $item): bool => 'Admin' === $item->text())->attr('href'), $link->attr('href'), 'the menu item and the button open the same page');
        self::assertCount(0, $page->filter('main button[disabled]'));
    }

    #[Test]
    public function aPersonWithNoRoleAtAllGetsTheWorkHome(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $this->logIn($this->client, $user);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('All clear, Lena', $page->filter('h1')->text());
        self::assertSame(['All', 'My work', 'My team'], $this->chipLabels($page));
        self::assertSame('You are not in a team yet.', $page->filter('section[aria-labelledby="home-your-team"] .empty-state')->text());
        self::assertCount(0, $page->filter('.home__team-stack'));
    }

    #[Test]
    public function everyHomeAsksTurboNotToCacheIt(): void
    {
        foreach (['Lena', 'Raj', 'Sara'] as $person) {
            self::ensureKernelShutdown();
            $this->client = self::httpsClient();
            $page = $this->openHomeAs($person);

            self::assertSame(['no-cache'], $page->filter('head meta[name="turbo-cache-control"]')->each(static fn (Crawler $meta): string => (string) $meta->attr('content')), $person);
        }
    }

    #[Test]
    public function theAdminSectionOfTheWorkHomeHasAnOpenSettingsLinkToTheAdminArea(): void
    {
        $page = $this->openHomeAs('Lena');

        $link = $page->filter('section[data-focus-group="admin"] .home__section-header a.btn-text');
        self::assertCount(1, $link);
        self::assertSame('Open settings', $link->text());
        self::assertSame('/admin', $link->attr('href'));
        self::assertCount(0, $page->filter('section:not([data-focus-group="admin"]) .home__section-header .btn'), 'only the Admin section has the button');
    }

    #[Test]
    public function theManagerSectionOfTheWorkHomeSaysYouAreAlsoAManager(): void
    {
        $this->setOrgRoles($this->person('Mia'), $this->acmeFoods(), '{manager}');

        $page = $this->openHomeAs('Mia');

        self::assertSame('All teams · you are also a manager', $page->filter('section[data-focus-group="all_teams"] h2')->text());
    }

    #[Test]
    public function theAsideIsLabelledNeutrallySoItNeverNamesAdminToAMember(): void
    {
        $page = $this->openHomeAs('Omar');

        self::assertSame('More for you', $page->filter('aside')->attr('aria-label'));
    }

    #[Test]
    public function teamsAtAGlanceShowsTheLegendOfTheFiveGroups(): void
    {
        $page = $this->openHomeAs('Raj');

        self::assertSame(['To do', 'Doing', 'Checking', 'Waiting', 'Done'], $page->filter('.home__legend li')->each(static fn (Crawler $group): string => $group->text()));
        self::assertCount(5, $page->filter('.home__legend .home__swatch[aria-hidden="true"]'));
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    private function openHomeAs(string $person): Crawler
    {
        $this->logIn($this->client, $this->person($person));

        $page = $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function chipLabels(Crawler $page): array
    {
        return $page->filter('.home__chips button.chip')->each(static fn (Crawler $chip): string => $chip->text());
    }

    /**
     * @return list<string>
     */
    private function pressedChipLabels(Crawler $page): array
    {
        return $page->filter('.home__chips button.chip[aria-pressed="true"]')->each(static fn (Crawler $chip): string => $chip->text());
    }

    /**
     * @return list<string>
     */
    private function menuLabels(Crawler $page): array
    {
        // A group's label is the first text of its button, as in MainMenuTest.
        return $page->filter('header nav[aria-label="Main"] > *')->each(static fn (Crawler $item): string => trim((string) ('div' === $item->nodeName() ? $item->filter('button') : $item)->getNode(0)?->firstChild?->textContent));
    }

    private function person(string $name): User
    {
        $this->acmeFoods();

        return $this->people[$name];
    }

    private function acmeFoods(): OrganizationId
    {
        if ([] !== $this->people) {
            return $this->acmeFoods;
        }

        $this->acmeFoods = $this->createOrganization('Acme Foods');
        $people = [];
        foreach (['Lena' => '{admin}', 'Omar' => '{}', 'Mia' => '{}', 'Raj' => '{manager}', 'Sara' => '{admin}', 'Noor' => '{manager}'] as $name => $orgRoles) {
            $people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($people[$name], $this->acmeFoods);
            $this->setOrgRoles($people[$name], $this->acmeFoods, $orgRoles);
        }
        $shop = $this->createTeam($this->acmeFoods, 'Shop', $people['Lena']);
        $mobile = $this->createTeam($this->acmeFoods, 'Mobile', $people['Lena']);
        $this->addTeamRole($people['Omar'], $this->acmeFoods, $shop, 'member');
        $this->addTeamRole($people['Omar'], $this->acmeFoods, $mobile, 'member');
        $this->addTeamRole($people['Mia'], $this->acmeFoods, $shop, 'member');
        $this->addTeamRole($people['Raj'], $this->acmeFoods, $shop, 'viewer');
        $this->people = $people;

        return $this->acmeFoods;
    }

    /**
     * A ping notification five minutes before NOW, open and due, on a ticket of Acme Foods that everyone may see.
     */
    private function addPingToOmarFromLena(): void
    {
        $organizationId = $this->acmeFoods()->toString();
        $lena = $this->person('Lena');
        $omar = $this->person('Omar');
        $ticketId = Uuid::v7()->toRfc4122();
        $this->ticketRows()->addOf($organizationId, $ticketId, Uuid::v7()->toRfc4122(), $lena->id()->toString());
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->insert('ntf_notification', [
            'organization_id' => $organizationId,
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $omar->id()->toString(),
            'kind' => 'ping',
            'actor_id' => $lena->id()->toString(),
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $lena->name(), 'ticketKey' => 'SHOP-1'], \JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-05 11:55:00+00',
        ]));
    }

    /**
     * TicketRows switches the tenant, which the switch guard refuses while the entity manager holds the test's users or
     * the last request left its tenant set; the users stay usable detached, as only their ids and names are read.
     */
    private function ticketRows(): TicketRows
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();

        return new TicketRows($this->migrator(), $tenantContext);
    }

    private function onlyOrganization(): OrganizationId
    {
        self::assertCount(1, $this->createdOrganizationIds);

        return new OrganizationId($this->createdOrganizationIds[0]);
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
    }
}
