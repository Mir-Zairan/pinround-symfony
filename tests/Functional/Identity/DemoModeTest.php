<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SymfonySessionConfirmations;
use App\Identity\UI\Controller\ConfirmItsYouController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Organization\LogsInAdmins;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The demo switch PINROUND_DEMO_SKIP_2FA (ADR-0049, P14.T18) through the real firewall, admin pages, token page,
 * OAuth consent page and "Confirm it's you".
 * The kernel refuses to boot the test env with the switch on, so "on in dev" is the container's RecentAuth replaced by
 * one built with the switch on and the dev environment, over the real session and two-step logins; the tests without
 * that replacement run the real wiring, where the switch is off. The client keeps its kernel, so the clock and the
 * replacement set in the container are the ones every request reads; the clock is fixed on 17 Nov 2026, before the
 * day this was written, as a clock ahead of the wall clock would end the session.
 */
final class DemoModeTest extends WebTestCase
{
    use Factories;
    use IssuesApiTokens;
    use LogsInAdmins;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use ReadsSessionMetadata;
    use RegistersOAuthClients;
    use TurnsOnTwoStepLogin;

    private const string BANNER = 'Demo mode: two-step login is skipped.';
    private const string PEOPLE_PAGE = '/admin/people';
    private const string TOKENS_PAGE = '/me/tokens';
    private const string SETUP_PAGE = '/me/security/two-step';
    private const string NOW = '2026-11-17 09:00:00 UTC';
    private const string OAUTH_CALLBACK = 'https://claude.example/api/mcp/auth_callback';
    /** The S256 challenge of RFC 7636 Appendix B. */
    private const string CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeApiTokens();
        $this->removeOAuthClientsAndCodes();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar logged in with his password more than 10 minutes ago and connects Claude Code: "Confirm it's you" asks for
     * his password only, records the password time and no two-step time, and goes on to the consent page he asked for.
     */
    #[Test]
    public function withTheSwitchOnInDevAnOldLoginConfirmsWithThePasswordOnlyAndGoesOnToTheConsentPage(): void
    {
        $this->turnOnTheDemoSwitch('dev');
        $clientId = $this->registerClient($this->client, 'Claude', [self::OAUTH_CALLBACK]);
        [$omar, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $this->logInWithThePassword($this->client, $omar);
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);
        $authorizePath = self::authorizePath($clientId);

        $confirmPage = $this->openConfirmItsYouFor($authorizePath);

        self::assertSame('To do this, type your password again.', $confirmPage->filter('main p.login__hint')->first()->text());
        self::assertCount(1, $confirmPage->filter('main form input[name="password"]'));
        self::assertCount(0, $confirmPage->filter('main form input[name="code"]'));
        self::assertSame([self::BANNER], self::bannersOf($confirmPage));

        $this->clock->sleep(5);
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(302);
        $this->assertSamePage($authorizePath, (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['user_id' => $omar->id()->toString(), 'at' => $this->clock->now()->getTimestamp()], self::sessionAttribute($this->client, SymfonySessionConfirmations::RECENT_AUTH_KEY));
        self::assertNull(self::sessionAttribute($this->client, SymfonySessionConfirmations::TWO_STEP_CONFIRMED_KEY));

        $consentPage = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Connect Claude to pinround?', $consentPage->filter('main h1')->text());
        $form = $consentPage->selectButton('Allow')->form();
        $form->setValues(['organization_id' => $acmeFoods->toString()]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith(self::OAUTH_CALLBACK.'?code=', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->authorizationCodeCountOf($acmeFoods));
        self::assertSame(0, $this->twoStepLoginCountOf($omar));
    }

    #[Test]
    public function withTheSwitchOnInDevAWrongPasswordIsRefusedAndRecordsNoProof(): void
    {
        $this->turnOnTheDemoSwitch('dev');
        $clientId = $this->registerClient($this->client, 'Claude', [self::OAUTH_CALLBACK]);
        [$omar, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $this->logInWithThePassword($this->client, $omar);
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);
        $authorizePath = self::authorizePath($clientId);
        $this->openConfirmItsYouFor($authorizePath);
        $recentAuthBefore = self::sessionAttribute($this->client, SymfonySessionConfirmations::RECENT_AUTH_KEY);
        self::assertNotNull($recentAuthBefore);

        $this->client->submitForm('Confirm', ['password' => 'not the password at all']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConfirmItsYouController::WRONG_PASSWORD, $this->client->getCrawler()->filter('main [role="alert"]#confirm-error')->text());
        self::assertSame($recentAuthBefore, self::sessionAttribute($this->client, SymfonySessionConfirmations::RECENT_AUTH_KEY));
        self::assertNull(self::sessionAttribute($this->client, SymfonySessionConfirmations::TWO_STEP_CONFIRMED_KEY));
        $this->client->request('GET', $authorizePath);
        $this->assertSentToConfirmItsYouFor($authorizePath);
        self::assertSame(0, $this->authorizationCodeCountOf($acmeFoods));
    }

    /**
     * The real wiring of the test env: an old login without two-step login still goes to its setup.
     */
    #[Test]
    public function withTheSwitchOffAnOldLoginWithoutTwoStepLoginIsStillSentToItsSetup(): void
    {
        $this->assertAnOldLoginIsSentToTwoStepSetup();
    }

    #[Test]
    public function withTheSwitchOnOutsideDevAnOldLoginWithoutTwoStepLoginIsStillSentToItsSetup(): void
    {
        $this->turnOnTheDemoSwitch('test');

        $this->assertAnOldLoginIsSentToTwoStepSetup();
    }

    /**
     * From the consent page and from "Confirm it's you" alike.
     */
    private function assertAnOldLoginIsSentToTwoStepSetup(): void
    {
        $clientId = $this->registerClient($this->client, 'Claude', [self::OAUTH_CALLBACK]);
        [$omar, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $this->logInWithThePassword($this->client, $omar);
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);
        $authorizePath = self::authorizePath($clientId);

        $this->client->request('GET', $authorizePath);
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        $this->client->request('GET', '/me/confirm?'.http_build_query(['return' => $authorizePath]));
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        self::assertSame(0, $this->authorizationCodeCountOf($acmeFoods));
    }

    #[Test]
    public function withTheSwitchOnInDevSaraOpensAdminPagesAndMakesATokenWithoutTwoStepLogin(): void
    {
        $this->turnOnTheDemoSwitch('dev');
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithThePassword($this->client, $sara);

        $peoplePage = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People and roles', $peoplePage->filter('main h1')->text());
        self::assertSame([self::BANNER], self::bannersOf($peoplePage));

        $page = $this->postNewToken(['name' => 'Sara laptop', 'scopes' => ['read'], 'lifetime_days' => '30', '_csrf_token' => $this->createCsrfToken()]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('main [role="alert"]'));
        self::assertMatchesRegularExpression('/\Apr_[A-Za-z0-9_-]{43}\z/', (string) $page->filter('main input[name="new_token"]')->attr('value'));
        self::assertSame([self::BANNER], self::bannersOf($page));
        self::assertSame(['Sara laptop'], $this->tokenNamesOf($sara, $acmeFoods));
        self::assertSame(0, $this->twoStepLoginCountOf($sara), 'Sara has a two-step login, so the switch was not what let her in');
    }

    /**
     * The switch skips the code only, never a role: Mia, a member without the Admin role, still gets the 404.
     */
    #[Test]
    public function withTheSwitchOnInDevMiaWithoutTheAdminRoleIsStillRefused(): void
    {
        $this->turnOnTheDemoSwitch('dev');
        [$mia] = $this->memberWithOrgRoles('Mia Lund', '{}');
        $this->logInWithThePassword($this->client, $mia);

        foreach (['/admin', self::PEOPLE_PAGE] as $adminPage) {
            $page = $this->client->request('GET', $adminPage);

            self::assertResponseStatusCodeSame(404, $adminPage);
            self::assertCount(0, $page->filter('main h1'), $adminPage);
        }
    }

    #[Test]
    public function withTheSwitchOnInDevTheLoginPageShowsTheBannerToo(): void
    {
        $this->turnOnTheDemoSwitch('dev');

        $loginPage = $this->client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::BANNER], self::bannersOf($loginPage));
    }

    /**
     * The real wiring of the test env: the switch is off, so nothing changes.
     */
    #[Test]
    public function withTheSwitchOffThereIsNoBannerAndAdminPagesAndTokensStillNeedTwoStepLogin(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');

        $this->assertTwoStepLoginIsStillNeeded($sara, $acmeFoods);
    }

    /**
     * RecentAuth checks the environment itself, so the switch on outside dev skips nothing even past the boot check.
     */
    #[Test]
    public function withTheSwitchOnOutsideDevThereIsNoBannerAndTwoStepLoginIsStillNeeded(): void
    {
        $this->turnOnTheDemoSwitch('test');
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');

        $this->assertTwoStepLoginIsStillNeeded($sara, $acmeFoods);
    }

    private function assertTwoStepLoginIsStillNeeded(User $sara, OrganizationId $acmeFoods): void
    {
        $loginPage = $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::bannersOf($loginPage));
        $this->logInWithThePassword($this->client, $sara);

        $home = $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], self::bannersOf($home));

        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseRedirects(self::SETUP_PAGE, 302);

        $this->postNewToken(['name' => 'Sara laptop', 'scopes' => ['read'], 'lifetime_days' => '30', '_csrf_token' => $this->createCsrfToken()]);
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        self::assertSame([], $this->tokenNamesOf($sara, $acmeFoods));
    }

    /**
     * Replaces RecentAuth before any request builds it, keeping the real session and two-step logins.
     */
    private function turnOnTheDemoSwitch(string $appEnvironment): void
    {
        $container = self::getContainer();
        $recentAuth = new RecentAuth($container->get(SessionConfirmations::class), $container->get(TwoStepLogins::class), true, $appEnvironment);
        $container->set(RecentAuth::class, $recentAuth);
    }

    private static function authorizePath(string $clientId): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::OAUTH_CALLBACK,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'demo-state',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Opens the consent page with an old login, which sends the person to "Confirm it's you" with the page to go back to.
     */
    private function openConfirmItsYouFor(string $authorizePath): Crawler
    {
        $this->client->request('GET', $authorizePath);
        $this->assertSentToConfirmItsYouFor($authorizePath);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    private function assertSentToConfirmItsYouFor(string $authorizePath): void
    {
        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/me/confirm?', $location);
        $query = self::queryOf($location);
        self::assertSame(['return'], array_keys($query));
        self::assertIsString($query['return']);
        $this->assertSamePage($authorizePath, $query['return']);
    }

    /**
     * The request URI comes back with the slashes of its query unescaped, so the paths are compared by their fields.
     */
    private function assertSamePage(string $expectedPath, string $actualPath): void
    {
        self::assertSame(parse_url($expectedPath, \PHP_URL_PATH), parse_url($actualPath, \PHP_URL_PATH));
        self::assertSame(self::queryOf($expectedPath), self::queryOf($actualPath));
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $query;
    }

    private function authorizationCodeCountOf(OrganizationId $organizationId): int
    {
        return $this->readAs($organizationId, static function (Connection $migrator): int {
            $count = $migrator->fetchOne('SELECT count(*) FROM idn_oauth_authorization_code');
            self::assertIsInt($count);

            return $count;
        });
    }

    /**
     * @return list<string>
     */
    private static function bannersOf(Crawler $page): array
    {
        return $page->filter('.demo-mode-banner')->each(static fn (Crawler $banner): string => trim($banner->text()));
    }

    private function twoStepLoginCountOf(User $user): int
    {
        $count = $this->migrator()->fetchOne('SELECT count(*) FROM idn_mfa_secret WHERE user_id = ?', [$user->id()->toString()]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * Opens the page, as a person does before they post its form.
     */
    private function createCsrfToken(): string
    {
        $page = $this->client->request('GET', self::TOKENS_PAGE);
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('main form[action="/me/tokens"] input[name="_csrf_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function postNewToken(array $fields): Crawler
    {
        return $this->client->request('POST', self::TOKENS_PAGE, $fields, server: ['HTTP_REFERER' => 'https://localhost/me/tokens']);
    }

    /**
     * @return list<mixed>
     */
    private function tokenNamesOf(User $user, OrganizationId $organizationId): array
    {
        return $this->inTokenTransactionAs($organizationId, fn (): array => $this->tokenEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT name FROM idn_api_token WHERE user_id = ? ORDER BY name',
            [$user->id()->toString()],
        ));
    }
}
