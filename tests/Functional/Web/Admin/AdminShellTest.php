<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web\Admin;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use App\Tests\Functional\Organization\LogsInAdmins;
use App\Tests\Functional\Web\CreatesTeams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The admin area's address /admin, its tab rail and the menu's Admin item, through the real firewall, login form and
 * code page over HTTPS (P14.T3). The client keeps its kernel, so the clock set in the container is the one every
 * request reads; it is fixed on 16 Nov 2026, before the day this was written, since a clock ahead of the wall clock
 * would end the session.
 */
final class AdminShellTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInAdmins;
    use LogsInUsers;
    use TurnsOnTwoStepLogin;

    private const string NOW = '2026-11-16 09:00:00 UTC';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    #[Test]
    public function anAdminAtAdminLandsOnTheFirstTabWithTheRailAndTheMenuItemCurrent(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/people', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['People and roles → /admin/people (current)', 'AI → /admin/ai'], self::railOf($page));
        self::assertSame(['Admin → /admin'], self::currentMenuItemsOf($page));
    }

    #[Test]
    public function theAiTabIsCurrentOnItsPageAndTheMenuItemStaysCurrent(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', '/admin/ai');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['People and roles → /admin/people', 'AI → /admin/ai (current)'], self::railOf($page));
        self::assertSame(['Admin → /admin'], self::currentMenuItemsOf($page));
    }

    #[Test]
    public function theInviteFormsAddressOpenedByHandSendsAnAdminToPeople(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $this->client->request('GET', '/admin/people/invite');

        self::assertResponseRedirects('/admin/people', 302);
    }

    /**
     * Two-step login is off, so the redirect is SendAdminToTwoStepProof's: the admin area is still asked first.
     */
    #[Test]
    public function anAdminWithoutTwoStepLoginIsSentToItsSetupFromAdmin(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithThePassword($this->client, $sara);

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/me/security/two-step', 302);
    }

    /**
     * @return iterable<string, array{string, string, 'lead'|'member'|'viewer'|null}>
     */
    public static function peopleWithoutTheAdminRole(): iterable
    {
        yield 'Raj, a manager' => ['Raj Patel', '{manager}', null];
        yield 'Lena, a lead' => ['Lena Berg', '{}', 'lead'];
        yield 'Omar, a member' => ['Omar Haddad', '{}', 'member'];
        yield 'Mia, a viewer' => ['Mia Chen', '{}', 'viewer'];
    }

    /**
     * With a fresh code, so only the role refuses: without HideAdminAreaFromNonAdmins it would be a 403, and without
     * the access check a redirect to a tab.
     *
     * @param 'lead'|'member'|'viewer'|null $teamRole
     */
    #[Test]
    #[DataProvider('peopleWithoutTheAdminRole')]
    public function aPersonWithoutTheAdminRoleGetsNotFoundAndNoAdminMenuItem(string $name, string $orgRoles, ?string $teamRole): void
    {
        [$person, $acmeFoods] = $this->memberWithOrgRoles($name, $orgRoles);
        if (null !== $teamRole) {
            $this->giveTeamRole($person, $acmeFoods, $teamRole);
        }
        $this->logInWithACode($this->client, $this->clock, $person);

        foreach (['/admin', '/admin/people/invite', '/admin/people', '/admin/ai'] as $path) {
            $page = $this->client->request('GET', $path);

            self::assertResponseStatusCodeSame(404, $path);
            self::assertFalse($this->client->getResponse()->headers->has('Location'), $path);
            self::assertCount(0, $page->filter('nav[aria-label="Admin"], .admin-rail, main form'), $path);
        }

        $home = $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $menu = $home->filter('header nav[aria-label="Main"]');
        self::assertCount(1, $menu);
        self::assertNotContains('Admin', $menu->children()->each(static fn (Crawler $item): string => $item->text()));
        self::assertCount(0, $home->filter('a[href^="/admin"]'));
    }

    /**
     * @return list<string> each rail tab as "label → href", with " (current)" on the current one
     */
    private static function railOf(Crawler $page): array
    {
        return $page->filter('main nav[aria-label="Admin"] a')->each(static fn (Crawler $tab): string => $tab->text().' → '.$tab->attr('href').('page' === $tab->attr('aria-current') ? ' (current)' : ''));
    }

    /**
     * @return list<string> the current items of the main menu as "label → href"
     */
    private static function currentMenuItemsOf(Crawler $page): array
    {
        return $page->filter('header nav[aria-label="Main"] a[aria-current="page"]')->each(static fn (Crawler $item): string => $item->text().' → '.$item->attr('href'));
    }

    /**
     * @param 'lead'|'member'|'viewer' $teamRole
     */
    private function giveTeamRole(User $person, OrganizationId $organizationId, string $teamRole): void
    {
        if ('lead' === $teamRole) {
            $this->createTeam($organizationId, 'Shop', $person);

            return;
        }
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $organizationId);
        $this->addTeamRole($person, $organizationId, $this->createTeam($organizationId, 'Shop', $lena), $teamRole);
    }
}
