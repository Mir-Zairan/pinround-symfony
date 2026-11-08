<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Domain\User;
use App\Organization\UI\OrganizationChoiceController;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\ReadsSessionMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The organization picker /org/choose and the tenant of each request (EnterChosenOrganization) through the real
 * firewall and session, over HTTPS. Each login through the form comes from a random IP, so the login counters do not
 * refuse it.
 */
final class OrganizationChoiceTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;

    private const string SESSION_COOKIE = '__Host-pinround';
    private const string NOT_IN_ANY_ORGANIZATION = 'You are not in any organization yet. Ask your admin to invite you.';

    #[Test]
    public function aUserInOneOrganizationLandsOnHomeWithoutThePickerOrTheSwitchItem(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');

        $this->logInThroughTheForm($client, $user);
        self::assertResponseRedirects('/', 302);
        $home = $client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertRouteSame('web_home');
        self::assertCount(0, $home->filter('a[href="/org/choose"]'));
        self::assertCount(0, $home->filter('.avatar-menu__organization'));
        self::assertStringNotContainsString('Switch organization', $home->filter('#avatar-menu-list')->text());
    }

    #[Test]
    public function aUserInTwoOrganizationsChoosesOneAndSwitchesToTheOther(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $betaBakery);

        $this->logInThroughTheForm($client, $user);
        self::assertResponseRedirects('/', 302);
        $client->followRedirect();
        self::assertResponseRedirects('/org/choose', 302);
        $picker = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Choose an organization', $picker->filter('main h1')->text());
        self::assertEqualsCanonicalizing(['Acme Foods', 'Beta Bakery'], $this->organizationButtons($picker));
        self::assertCount(0, $picker->filter('main .tag'));
        self::assertCount(0, $picker->filter('#avatar-menu-list a[href="/org/choose"]'), 'no link to the page itself');

        $client->submit($picker->selectButton('Acme Foods')->form());
        self::assertResponseRedirects('/', 302);
        $home = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Acme Foods', $home->filter('.avatar-menu__organization-name')->text());
        self::assertSame('Switch organization', $home->filter('#avatar-menu-list a[href="/org/choose"]')->text());

        $picker = $client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Current'], $picker->selectButton('Acme Foods')->closest('form')?->filter('.tag')->each(static fn (Crawler $tag): string => $tag->text()));
        $client->submit($picker->selectButton('Beta Bakery')->form());
        self::assertResponseRedirects('/', 302);
        $home = $client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Beta Bakery', $home->filter('.avatar-menu__organization-name')->text());
    }

    #[Test]
    public function switchingOrganizationsKeepsTheSessionAndDoesNotExtendItsThirtyDays(): void
    {
        $client = self::httpsClient();
        // The client keeps its kernel, so the clock stays in every service across requests. It starts at the real time:
        // the session's own timestamps come from PHP's time().
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $user = $this->createUserWithoutOrganization('Raj Patel');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $betaBakery);
        $this->logIn($client, $user);
        $this->choose($client, $acmeFoods);
        $created = time() - 30 * 86400 + 3600;
        self::writeSessionCreatedTime($client, $created);
        $sessionIdInAcmeFoods = $this->sessionId($client);

        $this->choose($client, $betaBakery);

        self::assertSame($created, self::sessionMetadata($client)['c']);
        self::assertSame($sessionIdInAcmeFoods, $this->sessionId($client));
        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Beta Bakery', $home->filter('.avatar-menu__organization-name')->text());
        // Two hours on, the login is past 30 days and the idle limit (8 h) is not reached.
        $clock->modify('+2 hours');
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aMemberRemovedFromTheChosenOrganizationIsSentToThePickerAtTheNextRequest(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Mia Lund');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $this->createOrganization('Beta Bakery'));
        $this->addMembership($user, $this->createOrganization('Gamma Grocers'));
        $this->logIn($client, $user);
        $this->choose($client, $acmeFoods);

        $this->removeMembership($user, $acmeFoods);
        $client->request('GET', '/');

        self::assertResponseRedirects('/org/choose', 302);
        $picker = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertEqualsCanonicalizing(['Beta Bakery', 'Gamma Grocers'], $this->organizationButtons($picker));
    }

    #[Test]
    public function aMemberRemovedFromTheChosenOrganizationMovesToTheOnlyOneLeft(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithoutOrganization('Raj Patel');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($user, $betaBakery);
        $this->logIn($client, $user);
        $this->choose($client, $acmeFoods);
        $tenantSettingsSeen = [];
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, static function () use ($connection, &$tenantSettingsSeen): void {
            $tenantSettingsSeen[] = $connection->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]);
        });

        $this->removeMembership($user, $acmeFoods);
        $home = $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertRouteSame('web_home');
        // The home request itself ran for the organization left, not only the picker afterwards.
        self::assertSame([$betaBakery->toString()], $tenantSettingsSeen);
        // One organization left: no switch item, so the name is not shown either.
        self::assertCount(0, $home->filter('a[href="/org/choose"]'));
        // The picker marks the session's choice, which is now the organization left.
        $picker = $client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Beta Bakery'], $this->organizationButtons($picker));
        self::assertSame(
            [$betaBakery->toString()],
            $picker->filter('main form')->reduce(static fn (Crawler $form): bool => 1 === $form->filter('.tag')->count())
                ->each(static fn (Crawler $form): string => (string) $form->filter('input[name="organization_id"]')->attr('value')),
        );
    }

    #[Test]
    public function choosingAnOrganizationOfSomeoneElseIsRefusedAndKeepsTheChoice(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Sara Novak');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $this->createOrganization('Beta Bakery'));
        $otherCompany = $this->createOrganization('Other Company');
        $this->logIn($client, $user);
        $this->choose($client, $acmeFoods);
        $picker = $client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        $csrfToken = (string) $picker->filter('main input[name="_csrf_token"]')->first()->attr('value');

        foreach ([$otherCompany->toString(), 'not-an-id'] as $refusedOrganizationId) {
            $client->request('POST', '/org/choose', ['organization_id' => $refusedOrganizationId, '_csrf_token' => $csrfToken]);
            self::assertResponseStatusCodeSame(403, $refusedOrganizationId);
        }

        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Acme Foods', $home->filter('.avatar-menu__organization-name')->text());
    }

    #[Test]
    public function aChoiceWithoutAValidCsrfTokenIsRefusedAndKeepsTheChoice(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $betaBakery);
        $this->logIn($client, $user);
        $this->choose($client, $acmeFoods);

        foreach (['', 'wrong-token'] as $csrfToken) {
            $page = $client->request('POST', '/org/choose', ['organization_id' => $betaBakery->toString(), '_csrf_token' => $csrfToken]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(OrganizationChoiceController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());
        }

        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Acme Foods', $home->filter('.avatar-menu__organization-name')->text());
    }

    #[Test]
    public function aLoggedOutVisitorOfThePickerIsSentToTheLogin(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/org/choose');
        self::assertResponseRedirects('/login', 302);

        $client->request('POST', '/org/choose', ['organization_id' => 'anything']);
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aUserInNoOrganizationIsToldSoAndCanStillOpenTheirAccountPages(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithoutOrganization('Omar Haddad'));

        $client->request('GET', '/');
        self::assertResponseRedirects('/org/choose', 302);
        $picker = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NOT_IN_ANY_ORGANIZATION, $picker->filter('main .empty-state p')->text());
        self::assertCount(0, $picker->filter('main form'));

        foreach (['/me/profile', '/me/security', '/me/security/two-step'] as $accountPage) {
            $client->request('GET', $accountPage);
            self::assertResponseStatusCodeSame(200, $accountPage);
        }
    }

    #[Test]
    public function onlyThePickerLoginAccountHealthAndGalleryRoutesWorkWithoutAnOrganizationAndEachExists(): void
    {
        $routeNames = self::getContainer()->getParameter('organization.routes_without_organization');
        $routes = self::getContainer()->get('router')->getRouteCollection();

        self::assertEqualsCanonicalizing([
            'organization_choose',
            'identity_login',
            'identity_login_code',
            'identity_logout',
            'identity_password_forgot',
            'identity_password_reset_token',
            'identity_password_reset',
            'identity_profile',
            'identity_theme',
            'identity_security',
            'identity_security_password',
            'identity_security_log_out_everywhere',
            'identity_two_step',
            'identity_two_step_start',
            'identity_two_step_confirm',
            'health_live',
            'health_ready',
            'web_component_gallery',
        ], $routeNames);
        foreach ($routeNames as $routeName) {
            self::assertNotNull($routes->get($routeName), $routeName);
        }
    }

    private function logInThroughTheForm(KernelBrowser $client, User $user): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);
    }

    private function choose(KernelBrowser $client, OrganizationId $organizationId): void
    {
        $picker = $client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        $form = $picker->filter(\sprintf('input[name="organization_id"][value="%s"]', $organizationId->toString()))->closest('form')?->form();
        self::assertNotNull($form);
        $client->submit($form);
        self::assertResponseRedirects('/', 302);
    }

    /**
     * @return list<string>
     */
    private function organizationButtons(Crawler $picker): array
    {
        return $picker->filter('main form button')->each(static fn (Crawler $button): string => $button->text());
    }

    private function sessionId(KernelBrowser $client): string
    {
        return (string) $client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);
    }

    private static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
