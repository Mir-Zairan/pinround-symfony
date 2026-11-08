<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginCodeController;
use App\Identity\UI\Controller\LoginController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * Login step two at /login/code through the real firewall, over HTTPS (FR-ID-03, ADR-0012). The client keeps its
 * kernel, so the clock set in the container is the one every request reads; it runs 7 minutes ahead of the wall
 * clock, so a code computed from the wall clock would be 14 steps off. The code counters live on the state Valkey per
 * user, and every test has new users.
 */
final class LoginCodeTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;
    use TurnsOnTwoStepLogin;

    private const string PAGE = '/login/code';
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
    public function theRightPasswordOfAUserWithTwoStepLoginLeadsToTheCodePageAndLogsNobodyIn(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $user);

        $this->submitPassword($user);

        self::assertResponseRedirects(self::PAGE, 302);
        self::assertNull(self::sessionAttribute($this->client, '_security_main'), 'the session holds a security token');
        self::assertNull(self::sessionAttribute($this->client, 'identity.recent_auth_at'));
        self::assertSame($user->id()->toString(), self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertSame($this->clock->now()->getTimestamp(), self::sessionAttribute($this->client, 'identity.pending_login_at'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextSame('title', 'Type your code · pinround');
        self::assertCount(0, $page->filter('header.site-header'));
        $card = $page->filter('main section.card');
        self::assertSame('Type your code', $card->filter('h1')->text());
        self::assertSame('Code from your app', trim($card->filter('label')->text()));
        self::assertSame('one-time-code', $card->filter('input[name="code"]')->attr('autocomplete'));
        self::assertSame('login-code-hint', $card->filter('input[name="code"]')->attr('aria-describedby'));
        self::assertSame('6 digits. No phone with you? Type one of your recovery codes instead.', $card->filter('#login-code-hint')->text());
        self::assertSame(['Log in', 'Cancel'], $card->filter('button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(0, $card->filter('[role="alert"]'));

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theRightCodeFromTheAppLogsInWithAPasswordTokenANewSessionIdAndBothSessionTimes(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $this->openCodePage($user);
        $sessionIdBefore = $this->sessionId();

        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/', 302);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotSame($sessionIdBefore, $this->sessionId(), 'the session kept its id');
        $now = $this->clock->now()->getTimestamp();
        self::assertSame($now, self::sessionAttribute($this->client, 'identity.recent_auth_at'));
        self::assertSame($now, self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_at'));
        $serializedToken = self::sessionAttribute($this->client, '_security_main');
        self::assertIsString($serializedToken);
        $token = unserialize($serializedToken);
        self::assertIsObject($token);
        self::assertSame(UsernamePasswordToken::class, $token::class);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('OH', $this->client->getCrawler()->filter('header.site-header [role="img"]')->text());
    }

    #[Test]
    public function theSameCodeFromTheAppIsRefusedAtASecondLogin(): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $code = self::codeOf($seed, $this->clock);
        $this->openCodePage($user);
        $this->client->submitForm('Log in', ['code' => $code]);
        self::assertResponseRedirects('/', 302);
        $this->client->getCookieJar()->clear();

        $this->openCodePage($user);
        $this->client->submitForm('Log in', ['code' => $code]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(LoginCodeController::WRONG_CODE, $this->client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertIsNotLoggedIn();
    }

    #[Test]
    public function aRecoveryCodeLogsInOnceAndIsRefusedAfterLoggingOut(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        [, $recoveryCodes] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($user);
        $this->client->submitForm('Log in', ['code' => $recoveryCodes[3]]);
        self::assertResponseRedirects('/', 302);
        self::assertSame($this->clock->now()->getTimestamp(), self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Log out');
        self::assertResponseRedirects('/login', 302);

        $this->openCodePage($user);
        $this->client->submitForm('Log in', ['code' => $recoveryCodes[3]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(LoginCodeController::WRONG_CODE, $this->client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertIsNotLoggedIn();
        $this->client->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Log in', ['code' => strtoupper(str_replace('-', ' ', $recoveryCodes[4]))]);
        self::assertResponseRedirects('/', 302, 'an unused recovery code did not log in');
    }

    #[Test]
    public function aWrongCodeShowsOneNeutralMessageAndTheSixthTryIsRefusedEvenWhenRight(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $this->openCodePage($user);

        foreach ([self::wrongCodeFor($seed, $this->clock), 'k7m2q-x9p4t', self::wrongCodeFor($seed, $this->clock), 'not a code', '123'] as $try => $wrongCode) {
            $this->client->submitForm('Log in', ['code' => $wrongCode]);
            self::assertResponseStatusCodeSame(422, 'try '.($try + 1));
            $page = $this->client->getCrawler();
            self::assertSame(LoginCodeController::WRONG_CODE, $page->filter('main [role="alert"]#login-code-error')->text());
            self::assertSame('true', $page->filter('main input[name="code"]')->attr('aria-invalid'));
            self::assertSame('login-code-error login-code-hint', $page->filter('main input[name="code"]')->attr('aria-describedby'));
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }

        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Too many tries. Wait 15 minutes.', $this->client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertIsNotLoggedIn();
    }

    #[Test]
    public function aPendingLoginOlderThanFiveMinutesSendsThePersonBackToTheLoginPage(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($user);
        $this->clock->sleep(301);

        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/login', 302);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_at'));
        $loginPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Log in again, then type your code within 5 minutes.', $loginPage->filter('main [role="status"]')->text());
        self::assertIsNotLoggedIn();
    }

    #[Test]
    public function aPendingLoginOfExactlyFiveMinutesStillTakesTheCode(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($user);
        $this->clock->sleep(300);

        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function withoutAPendingLoginThePageSendsTheVisitorToTheLoginPage(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, self::PAGE, ['code' => '123456']);

            self::assertResponseRedirects('/login', 302, $method);
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
            $loginPage = $this->client->followRedirect();
            self::assertResponseStatusCodeSame(200);
            self::assertSame('Log in again, then type your code within 5 minutes.', $loginPage->filter('main [role="status"]')->text());
        }
    }

    #[Test]
    public function aLoggedInUserIsSentHome(): void
    {
        $this->logIn($this->client, $this->createUserWithPassword('Mia Lund'));

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, self::PAGE, ['code' => '123456']);

            self::assertResponseRedirects('/', 302, $method);
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenIsRefusedAndSpendsNoCode(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $codePage = $this->openCodePage($user);
        $code = self::codeOf($seed, $this->clock);

        foreach ([[], ['_csrf_token' => 'forged'], ['cancel' => '1'], ['cancel' => '1', '_csrf_token' => 'forged']] as $fields) {
            $this->client->request('POST', self::PAGE, ['code' => $code, ...$fields]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(LoginController::FORM_EXPIRED, $this->client->getCrawler()->filter('main [role="alert"]')->text());
        }
        self::assertSame($user->id()->toString(), self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertIsNotLoggedIn();

        $this->client->request('POST', self::PAGE, ['code' => $code, '_csrf_token' => $codePage->filter('main form input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects('/', 302, 'the refused posts spent the code');
    }

    #[Test]
    public function aUserBlockedAfterTheirPasswordCannotLogInWithARightCode(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $this->openCodePage($user);
        $this->blockUser($user);

        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/login', 302);
        self::assertNull(self::sessionAttribute($this->client, '_security_main'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertIsNotLoggedIn();
    }

    #[Test]
    public function cancelEndsThePendingLogin(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($user);

        $this->client->submitForm('Cancel');

        self::assertResponseRedirects('/login', 302);
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_user_id'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.pending_login_at'));
        $loginPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $loginPage->filter('main [role="status"]'));
        $this->client->request('GET', self::PAGE);
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theLoginWithACodeLandsOnThePageAskedForBeforeIt(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $this->client->request('GET', '/me/security');
        self::assertResponseRedirects('/login', 302);

        $this->openCodePage($user);
        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('https://localhost/me/security', 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    private function submitPassword(User $user): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);
    }

    private function openCodePage(User $user): Crawler
    {
        $this->submitPassword($user);
        self::assertResponseRedirects(self::PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    private function sessionId(): ?string
    {
        return $this->client->getCookieJar()->get(self::SESSION_COOKIE, '/', 'localhost')?->getValue();
    }

    private function assertIsNotLoggedIn(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }
}
