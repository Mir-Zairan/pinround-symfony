<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\UI\Controller\RecentAuthRedirects;
use App\Organization\UI\EnterChosenOrganization;
use App\Organization\UI\SendAdminToTwoStepProof;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Fixtures\Http\RecentAuthProbeController;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\ReadsSessionMetadata;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * ADMIN_AREA end to end, through the test-only admin page /_test/admin-area (config/routes/test_fixtures.yaml), the
 * real login form, the code page and the real firewall over HTTPS (ADR-0012, FR-ID-06). The client keeps its kernel,
 * so the clock set in the container is the one every request reads; it runs 7 minutes ahead of the wall clock. Each
 * client comes from a random IP, so the login counters never refuse, and every test has new users.
 */
final class AdminAreaTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use LogsInUsers;
    use ReadsSessionMetadata;
    use TurnsOnTwoStepLogin;

    private const string ADMIN_AREA = '/_test/admin-area';
    private const string CONFIRM_FOR_THE_ADMIN_AREA = '/me/confirm?return=/_test/admin-area';
    private const string SETUP_PAGE = '/me/security/two-step';
    private const string SESSION_COOKIE = '__Host-pinround';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock();
        $this->clock->modify('+7 minutes');
        self::getContainer()->set('clock', $this->clock);
    }

    #[Test]
    public function anAdminWithoutTwoStepLoginLandsOnTheSetupPageAndComesBackAfterIt(): void
    {
        [$lena, $acmeFoods] = $this->memberWithOrgRoles('Lena Berg', '{admin}');
        $this->logInWithThePassword($this->client, $lena);

        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        self::assertSame(self::ADMIN_AREA, self::sessionAttribute($this->client, RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY));
        $setupPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SendAdminToTwoStepProof::ADMIN_PAGES_NEED_TWO_STEP_LOGIN, $setupPage->filter('main [role="status"]')->text());

        $this->client->submitForm('Set up two-step login');
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $seed = str_replace(' ', '', $page->filter('main .two-step__key')->text());
        $this->client->submitForm('Turn on', ['code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::ADMIN_AREA, $this->client->getCrawler()->selectLink('Continue')->attr('href'));

        $this->client->clickLink('Continue');

        $this->assertAdminAreaOpensFor($acmeFoods);
    }

    #[Test]
    public function anAdminWhoseLastCodeIsOverAnHourOldIsAskedForThePasswordAndACodeAgain(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $seed = $this->logInWithACode($this->client, $this->clock, $sara);

        $this->clock->sleep(59 * 60);
        $this->client->request('GET', self::ADMIN_AREA);
        $this->assertAdminAreaOpensFor($acmeFoods);

        $this->clock->sleep(60 + 1);
        $this->client->request('GET', self::ADMIN_AREA);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ADMIN_AREA, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects(self::ADMIN_AREA, 302);
        $this->client->followRedirect();
        $this->assertAdminAreaOpensFor($acmeFoods);
    }

    /**
     * A password login from before two-step login was turned on, in another session, holds no code.
     */
    #[Test]
    public function anAdminWithTwoStepLoginOnButNoCodeInThisSessionIsAskedForOne(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithThePassword($this->client, $sara);
        $passwordOnlySession = $this->takeTheSessionCookie();
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $sara);
        $this->clock->sleep(30);
        $this->client->getCookieJar()->set($passwordOnlySession);

        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ADMIN_AREA, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects(self::ADMIN_AREA, 302);
        $this->client->followRedirect();
        $this->assertAdminAreaOpensFor($acmeFoods);
    }

    /**
     * Session B typed a code; session A of the same person then turns two-step login off, and later on again with a
     * new key. Neither the admin area nor an action marked RequiresRecentAuth may open in B on its old proof.
     */
    #[Test]
    public function turningTwoStepLoginOffOrSettingItUpAgainInAnotherSessionEndsTheProofHere(): void
    {
        [$lena, $acmeFoods] = $this->memberWithOrgRoles('Lena Berg', '{admin}');
        $seed = $this->logInWithACode($this->client, $this->clock, $lena);
        $this->client->request('GET', self::ADMIN_AREA);
        $this->assertAdminAreaOpensFor($acmeFoods);
        $this->client->request('GET', '/_test/recent-auth');
        self::assertResponseStatusCodeSame(200);
        $sessionB = $this->takeTheSessionCookie();

        $this->clock->sleep(30);
        $this->openCodePage($this->client, $lena);
        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects('/', 302);
        $this->clock->sleep(30);
        $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Turn off two-step login', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects('/me/security', 302);
        $sessionA = $this->takeTheSessionCookie();

        $this->client->getCookieJar()->set($sessionB);
        $this->client->request('GET', self::ADMIN_AREA);
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        $this->client->request('GET', '/_test/recent-auth');
        self::assertResponseRedirects(self::SETUP_PAGE, 302);
        $this->takeTheSessionCookie();

        $this->clock->sleep(30);
        $this->client->getCookieJar()->set($sessionA);
        $this->client->request('GET', self::SETUP_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Set up two-step login');
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $newSeed = str_replace(' ', '', $page->filter('main .two-step__key')->text());
        $this->client->submitForm('Turn on', ['code' => self::codeOf($newSeed, $this->clock)]);
        self::assertResponseStatusCodeSame(200);
        $this->takeTheSessionCookie();

        $this->client->getCookieJar()->set($sessionB);
        $this->client->request('GET', self::ADMIN_AREA);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ADMIN_AREA, 302);
        $this->client->request('GET', '/_test/recent-auth');
        self::assertResponseRedirects('/me/confirm?return=/_test/recent-auth', 302);
        self::assertStringNotContainsString(RecentAuthProbeController::OPENED, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aManagerWithAFreshCodeIsRefusedWithoutARedirect(): void
    {
        [$raj] = $this->memberWithOrgRoles('Raj Patel', '{manager}');
        $this->logInWithACode($this->client, $this->clock, $raj);

        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aMemberWithoutAnOrgRoleIsRefusedWithoutARedirect(): void
    {
        [$omar] = $this->memberWithOrgRoles('Omar Haddad', '{}');
        $this->logInWithThePassword($this->client, $omar);

        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Lena is an Admin of Beta Bakery only; in Acme Foods she is a plain member. The same session, with a fresh code,
     * opens the admin area of the organization she chooses only where she is an Admin.
     */
    #[Test]
    public function anAdminOfAnotherOrganizationIsRefusedWithoutARedirect(): void
    {
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($lena, $acmeFoods);
        $this->addMembership($lena, $betaBakery);
        $this->setOrgRoles($lena, $betaBakery, '{admin}');
        $this->logInWithACode($this->client, $this->clock, $lena);

        self::writeSessionAttribute($this->client, EnterChosenOrganization::SESSION_KEY, $acmeFoods->toString());
        $this->client->request('GET', self::ADMIN_AREA);
        self::assertResponseStatusCodeSame(403);

        self::writeSessionAttribute($this->client, EnterChosenOrganization::SESSION_KEY, $betaBakery->toString());
        $this->client->request('GET', self::ADMIN_AREA);
        $this->assertAdminAreaOpensFor($betaBakery);
    }

    /**
     * The test browser's own login is a token kind that is no password session, as remember-me and the personal access
     * tokens of P09 are; two-step login on and a fresh code in the session do not change that.
     */
    #[Test]
    public function anAdminWhoseLoginIsNotAPasswordSessionIsRefusedWithoutARedirect(): void
    {
        [$lena] = $this->memberWithOrgRoles('Lena Berg', '{admin}');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $lena);
        $this->logIn($this->client, $lena);
        $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        self::writeSessionAttribute($this->client, 'identity.two_step_confirmed_at', ['user_id' => $lena->id()->toString(), 'at' => $this->clock->now()->getTimestamp()]);

        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', self::ADMIN_AREA);

        self::assertResponseRedirects('/login', 302);
    }

    private function assertAdminAreaOpensFor(OrganizationId $organizationId): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => $organizationId->toString()], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * Removes the session cookie from the client, so the next request starts another session.
     */
    private function takeTheSessionCookie(): Cookie
    {
        $cookie = $this->client->getCookieJar()->get(self::SESSION_COOKIE, '/', 'localhost');
        self::assertNotNull($cookie, 'the client has no session');
        $this->client->getCookieJar()->clear();

        return $cookie;
    }
}
