<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\UI\Controller\LoginController;
use App\Tests\Factory\Identity\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Zenstruck\Foundry\Test\Factories;

/**
 * The login form through the real firewall, over HTTPS (the session cookie is Secure). The login counters live on the
 * state Valkey and outlast a test run, so every client sends its tries from its own random IP.
 */
final class LoginTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;

    private const string SESSION_COOKIE = '__Host-pinround';

    #[Test]
    public function theRightPasswordLandsOnHomeWhereTheHeaderShowsTheUsersInitials(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/', 302);
        $home = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $avatar = $home->filter('header.site-header [role="img"]');
        self::assertCount(1, $avatar);
        self::assertSame('LB', $avatar->text());
        self::assertSame('Lena Berg', $avatar->attr('aria-label'));
        self::assertSame('Lena Berg', $avatar->attr('title'));
        self::assertSame('site-header__avatar', $avatar->attr('class'));
        self::assertSame('Log out', $home->filter('header form[action="/logout"][method="post"] button[type="submit"]')->text());
    }

    /**
     * The clock runs 7 minutes ahead of the wall clock, so a time taken from PHP's time() would differ.
     */
    #[Test]
    public function theRightPasswordOfAUserWithoutTwoStepLoginLogsInAtOnceAndRecordsTheLoginTime(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $clock = new MockClock();
        $clock->modify('+7 minutes');
        self::getContainer()->set('clock', $clock);
        $user = $this->createUserWithPassword('Omar Haddad');

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/', 302);
        self::assertSame(['user_id' => $user->id()->toString(), 'at' => $clock->now()->getTimestamp()], self::sessionAttribute($client, 'identity.recent_auth_at'));
        self::assertNull(self::sessionAttribute($client, 'identity.two_step_confirmed_at'));
        self::assertNull(self::sessionAttribute($client, 'identity.pending_login_user_id'));
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function aWrongPasswordAndAnUnknownEmailShowTheSameMessageAndKeepTheEmail(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');

        $this->submitLogin($client, $user->email()->toString(), 'not the password at all');
        self::assertResponseRedirects('/login', 302);
        $afterWrongPassword = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        $this->submitLogin($client, 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example', self::PASSWORD);
        self::assertResponseRedirects('/login', 302);
        $afterUnknownEmail = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        self::assertSame(LoginController::WRONG_CREDENTIALS, $afterWrongPassword->filter('[role="alert"]')->text());
        self::assertSame(LoginController::WRONG_CREDENTIALS, $afterUnknownEmail->filter('[role="alert"]')->text());
        self::assertSame($user->email()->toString(), $afterWrongPassword->filter('input[name="email"]')->attr('value'));
        self::assertIsNotLoggedIn($client);
    }

    #[Test]
    public function aBlockedUserWithTheRightPasswordGetsTheSameMessage(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $this->blockUser($user);

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(LoginController::WRONG_CREDENTIALS, $page->filter('[role="alert"]')->text());
        self::assertIsNotLoggedIn($client);
    }

    #[Test]
    public function aUserWithoutAPasswordGetsTheSameMessage(): void
    {
        $client = self::httpsClient();
        $user = UserFactory::createOne(['name' => 'Raj Patel']);
        $this->createdUserIds[] = $user->id()->toString();

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(LoginController::WRONG_CREDENTIALS, $page->filter('[role="alert"]')->text());
    }

    #[Test]
    public function theSixthTryWithinFifteenMinutesIsRefusedEvenWithTheRightPassword(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $email = $user->email()->toString();

        // Any letter case counts against the same email.
        foreach ([$email, strtoupper($email), $email, ucfirst($email), $email] as $typedEmail) {
            $this->submitLogin($client, $typedEmail, 'not the password at all');
            self::assertResponseRedirects('/login', 302);
            $page = $client->followRedirect();
            self::assertSame(LoginController::WRONG_CREDENTIALS, $page->filter('[role="alert"]')->text());
        }

        $this->submitLogin($client, $email, self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Too many tries. Wait 15 minutes.', $page->filter('[role="alert"]')->text());
        self::assertIsNotLoggedIn($client);
    }

    #[Test]
    public function anUnknownEmailIsThrottledWithTheSameMessage(): void
    {
        $client = self::httpsClient();
        $email = 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example';

        for ($try = 1; $try <= 5; ++$try) {
            $this->submitLogin($client, $email, self::PASSWORD);
            self::assertResponseRedirects('/login', 302);
            $client->followRedirect();
        }
        $this->submitLogin($client, $email, self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Too many tries. Wait 15 minutes.', $page->filter('[role="alert"]')->text());
    }

    #[Test]
    public function fiftyFailedTriesFromOneIpRefuseAnyEmailForTheRestOfTheHour(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        for ($try = 1; $try <= 50; ++$try) {
            $this->submitLogin($client, 'nobody'.$try.'.'.bin2hex(random_bytes(4)).'@acme-foods.example', self::PASSWORD);
            self::assertResponseRedirects('/login', 302);
        }

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Too many tries. Wait 60 minutes.', $page->filter('[role="alert"]')->text());
        self::assertIsNotLoggedIn($client);
    }

    /**
     * Like an office behind one NAT: only wrong tries count toward the IP's 50 per hour.
     */
    #[Test]
    public function sixtySuccessfulLoginsFromOneIpWithinAnHourAreAllAccepted(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');

        for ($login = 1; $login <= 60; ++$login) {
            $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);
            self::assertResponseRedirects('/', 302, 'login '.$login);
            $client->getCookieJar()->clear();
        }
    }

    #[Test]
    public function theFiftyFirstWrongTryFromOneIpIsRefusedAlsoAfterSuccessfulLogins(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        for ($login = 1; $login <= 5; ++$login) {
            $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);
            self::assertResponseRedirects('/', 302);
            $client->getCookieJar()->clear();
        }
        for ($try = 1; $try <= 50; ++$try) {
            $this->submitLogin($client, 'nobody'.$try.'.'.bin2hex(random_bytes(4)).'@acme-foods.example', self::PASSWORD);
            self::assertResponseRedirects('/login', 302);
            $page = $client->followRedirect();
            self::assertSame(LoginController::WRONG_CREDENTIALS, $page->filter('[role="alert"]')->text());
        }

        $this->submitLogin($client, 'nobody51.'.bin2hex(random_bytes(4)).'@acme-foods.example', self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Too many tries. Wait 60 minutes.', $page->filter('[role="alert"]')->text());
    }

    #[Test]
    public function aStringThatIsNoEmailGetsTheSameMessage(): void
    {
        $client = self::httpsClient();

        $this->submitLogin($client, 'not an email', self::PASSWORD);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(LoginController::WRONG_CREDENTIALS, $page->filter('[role="alert"]')->text());
    }

    #[Test]
    public function theOpenSessionOfAUserWhosePasswordWasRemovedEnds(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $this->logIn($client, $user);
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $this->migrator()->executeStatement('DELETE FROM idn_credential WHERE user_id = ?', [$user->id()->toString()]);
        $client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theSameEmailFromAnotherIpIsNotThrottled(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        for ($try = 1; $try <= 5; ++$try) {
            $this->submitLogin($client, $user->email()->toString(), 'not the password at all');
            $client->followRedirect();
        }

        $client->setServerParameter('REMOTE_ADDR', self::randomIp());
        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function aFormWithAWrongCsrfTokenDoesNotLogIn(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD, '_csrf_token' => 'forged']);

        self::assertResponseRedirects('/login', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(LoginController::FORM_EXPIRED, $page->filter('[role="alert"]')->text());
        self::assertIsNotLoggedIn($client);
    }

    #[Test]
    public function theLoginPageIsACardWithTheLogoTwoFieldsAndOneButton(): void
    {
        $client = self::httpsClient();

        $page = $client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('title', 'Log in · pinround');
        self::assertCount(0, $page->filter('header.site-header'));
        self::assertCount(1, $page->filter('link[rel="stylesheet"][href^="/assets/styles/login-"]'));
        $card = $page->filter('main section.card');
        self::assertCount(1, $card);
        self::assertSame('pinround', trim($card->filter('.logo')->text()));
        self::assertSame('Log in', $card->filter('h1')->text());
        self::assertCount(0, $card->filter('[role="alert"]'));
        self::assertSame(['Email', 'Password'], $card->filter('label')->each(static fn ($label): string => trim($label->text())));
        self::assertSame(['email', 'password', 'hidden'], $card->filter('input')->each(static fn ($input): string => (string) $input->attr('type')));
        self::assertNotSame('', (string) $card->filter('input[name="_csrf_token"]')->attr('value'));
        self::assertSame(['Log in'], $card->filter('button')->each(static fn ($button): string => $button->text()));
        self::assertSame('btn btn-filled', $card->filter('button')->attr('class'));
    }

    #[Test]
    public function aLoggedInUserOpeningTheLoginPageGoesHome(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Lena Berg'));

        $client->request('GET', '/login');

        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function anAnonymousVisitorOfHomeIsSentToTheLoginPage(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theOpenSessionOfAUserWhoGetsBlockedEnds(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $this->logIn($client, $user);
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $this->blockUser($user);
        $client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theSessionCookieIsAHostOnlySecureHttpOnlyLaxCookie(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        $cookie = self::sessionCookieOf($client);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        $setCookie = (string) $client->getResponse()->headers->get('Set-Cookie');
        self::assertStringStartsWith(self::SESSION_COOKIE.'=', $setCookie);
        self::assertStringEndsWith('; path=/; secure; httponly; samesite=lax', $setCookie);
        self::assertStringNotContainsStringIgnoringCase('domain=', $setCookie);
    }

    #[Test]
    public function theSessionIdChangesAtLogin(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $idBeforeLogin = self::sessionCookieOf($client)->getValue();

        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);

        self::assertResponseRedirects('/', 302);
        $idAfterLogin = self::sessionCookieOf($client)->getValue();
        self::assertNotSame('', (string) $idBeforeLogin);
        self::assertNotSame($idBeforeLogin, $idAfterLogin);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }

    private static function assertIsNotLoggedIn(KernelBrowser $client): void
    {
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }

    private static function sessionCookieOf(KernelBrowser $client): Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if (self::SESSION_COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('The response set no session cookie.');
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
