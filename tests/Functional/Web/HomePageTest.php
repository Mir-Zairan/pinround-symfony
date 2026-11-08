<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Web\UI\Navigation\MainMenu;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
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
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function leaOpensTheWorkHomeWithAllFourChipsAndAllSectionsShown(): void
    {
        $page = $this->openHomeAs('Lena');

        self::assertSame('All clear, Lena', $page->filter('h1')->text());
        self::assertSame(self::LEAD_LINE, $page->filter('.home__subline')->text());
        self::assertSame(['All', 'My work', 'My team', 'Admin'], $this->chipLabels($page));
        self::assertSame(['All'], $this->pressedChipLabels($page));
        self::assertSame(
            ['Needs you now', 'Also waiting on you', 'Admin', 'Pings for you', 'Release', 'Your team'],
            $page->filter('section.home__section > h2')->each(static fn (Crawler $title): string => $title->text()),
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
            ['Needs you now', 'Also waiting on you', 'Pings for you', 'Your team'],
            $page->filter('section.home__section > h2')->each(static fn (Crawler $title): string => $title->text()),
        );
        self::assertCount(0, $page->filter('.home__subline'), 'the job of a member is not known to Home yet');
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
        self::assertCount(3, $myWork);
        self::assertCount(3, $page->filter('section[data-focus-group="my_work"][hidden]'));
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
    public function theDateLineIsTodayInTheAppTimeZone(): void
    {
        $this->client->disableReboot();
        // An hour behind the real time stays within the session limits, which PHP's time() stamps. Home cannot read the
        // user's own time zone yet (SecurityUser does not carry it), so the app's default zone applies.
        $clock = new MockClock(new \DateTimeImmutable('@'.(time() - 3600)));
        self::getContainer()->set('clock', $clock);
        $this->logIn($this->client, $this->person('Lena'));

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('l, j F'), $page->filter('.home__date')->text());
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
        self::assertSame(['Home', 'Tickets', 'Admin'], $this->menuLabels($page));
    }

    #[Test]
    public function saraWhoIsOnlyAdminOpensYourOrganizationWithOnlyHomeAndAdminInTheMenu(): void
    {
        $page = $this->openHomeAs('Sara');

        self::assertSame('Your organization', $page->filter('h1')->text());
        self::assertCount(0, $page->filter('.home__date, .home__chips, button.chip'));
        self::assertSame(['Home', 'Admin'], $this->menuLabels($page));
        self::assertSame(
            ['People: —', 'Teams: 2', 'AI help: —', 'Jira: —'],
            $page->filter('.home__stat')->each(static fn (Crawler $card): string => $card->filter('.home__stat-label')->text().': '.trim((string) $card->filter('.home__stat-value')->getNode(0)?->firstChild?->textContent)),
        );
        self::assertSame('Admins do not work on tickets. Everything you set is under Admin.', $page->filter('main p')->text());
    }

    #[Test]
    public function theAdminSettingsButtonIsInertWhileTheAdminPageIsNotBuilt(): void
    {
        $page = $this->openHomeAs('Sara');

        self::assertNull(self::getContainer()->get('router')->getRouteCollection()->get(MainMenu::ADMIN_ROUTE), MainMenu::ADMIN_ROUTE.' has a page now: Home links to it');
        $button = $page->filter('main button.btn-filled');
        self::assertCount(1, $button);
        self::assertSame('Open admin settings (not built yet)', $button->text());
        self::assertNotNull($button->attr('disabled'));
        self::assertCount(0, $page->filter('main a'));
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
        return $page->filter('header nav[aria-label="Main"] > *')->each(static fn (Crawler $item): string => trim((string) $item->getNode(0)?->firstChild?->textContent));
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
