<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AccountPermission;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\PasswordResetController;
use App\Identity\UI\Controller\SecurityPageController;
use App\Identity\UI\Voter\OwnAccountVoter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Test\Factories;

/**
 * /me/security through the real firewall, over HTTPS: change password and "Log out of all devices" (FR-ID-06). Each
 * test has new users, so the per-user try counter on the state Valkey starts empty; the login checks come from a
 * random IP each, so the login counters do not refuse them.
 */
final class SecurityPageTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;

    private const string NEW_PASSWORD = 'a much longer new passphrase';
    private const string HASH_QUERY = 'SELECT password_hash FROM idn_credential WHERE user_id = ?';

    #[Test]
    public function changingThePasswordKeepsThisBrowserInAndSendsTheOtherOneToTheLoginPage(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $otherBrowser = self::anotherClient();
        $this->logIn($client, $user);
        $this->logIn($otherBrowser, $user);
        $sessionIdBefore = self::sessionIdOf($client);

        $this->submitPasswordChange($client, self::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/me/security', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SecurityPageController::PASSWORD_CHANGED, $page->filter('main [role="status"]')->text());
        self::assertNotSame($sessionIdBefore, self::sessionIdOf($client), 'the session kept its id');
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertLandsOnTheLoginPage($otherBrowser);

        $loginBrowser = self::anotherClient();
        $this->submitLogin($loginBrowser, $user, self::PASSWORD);
        self::assertSame('/login', parse_url((string) $loginBrowser->getResponse()->headers->get('Location'), \PHP_URL_PATH), 'the old password still logs in');
        $this->submitLogin($loginBrowser, $user, self::NEW_PASSWORD);
        self::assertSame('/', parse_url((string) $loginBrowser->getResponse()->headers->get('Location'), \PHP_URL_PATH), 'the new password does not log in');
    }

    #[Test]
    public function aWrongCurrentPasswordChangesNothingAndKeepsTheOtherBrowserIn(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        $otherBrowser = self::anotherClient();
        $this->logIn($client, $user);
        $this->logIn($otherBrowser, $user);
        $hashBefore = $this->passwordHashOf($user);

        $this->submitPasswordChange($client, 'not the current password', self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(SecurityPageController::WRONG_CURRENT_PASSWORD, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame($hashBefore, $this->passwordHashOf($user));
        $otherBrowser->request('GET', '/');
        self::assertSame(200, $otherBrowser->getResponse()->getStatusCode());
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function afterFiveWrongTriesEvenTheRightPasswordWaitsFifteenMinutes(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $this->logIn($client, $user);
        $hashBefore = $this->passwordHashOf($user);
        for ($try = 1; $try <= 5; ++$try) {
            $this->submitPasswordChange($client, 'wrong guess '.$try, self::NEW_PASSWORD);
            self::assertResponseStatusCodeSame(422);
        }

        $this->submitPasswordChange($client, self::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Too many tries. Wait 15 minutes.', $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame($hashBefore, $this->passwordHashOf($user));
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function theTriesOfOneUserDoNotCountForAnother(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Raj Patel'));
        for ($try = 1; $try <= 5; ++$try) {
            $this->submitPasswordChange($client, 'wrong guess '.$try, self::NEW_PASSWORD);
            self::assertResponseStatusCodeSame(422);
        }
        $this->submitPasswordChange($client, 'wrong guess 6', self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(429);
        $sara = $this->createUserWithPassword('Sara Holm');
        $sarasBrowser = self::anotherClient();
        $this->logIn($sarasBrowser, $sara);

        $this->submitPasswordChange($sarasBrowser, self::PASSWORD, self::NEW_PASSWORD);

        self::assertSame(302, $sarasBrowser->getResponse()->getStatusCode());
        self::assertSame('/me/security', parse_url((string) $sarasBrowser->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    #[Test]
    public function aShortOrMismatchedNewPasswordIsRefusedWithoutTakingATry(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $this->logIn($client, $user);
        $hashBefore = $this->passwordHashOf($user);

        // Five of each: if either kind took a try, the good change below would be the sixth and be refused.
        for ($try = 1; $try <= 5; ++$try) {
            $this->submitPasswordChange($client, self::PASSWORD, 'eleven char');
            self::assertResponseStatusCodeSame(422);
            self::assertSame('A password needs at least 12 characters.', $client->getCrawler()->filter('main [role="alert"]')->text());
            $this->submitPasswordChange($client, self::PASSWORD, self::NEW_PASSWORD, self::NEW_PASSWORD.'!');
            self::assertResponseStatusCodeSame(422);
            self::assertSame(PasswordResetController::PASSWORDS_DIFFER, $client->getCrawler()->filter('main [role="alert"]')->text());
        }
        self::assertSame($hashBefore, $this->passwordHashOf($user));

        $this->submitPasswordChange($client, self::PASSWORD, self::NEW_PASSWORD);
        self::assertResponseRedirects('/me/security', 302);
    }

    #[Test]
    public function aPasswordChangeRestartsTheThirtyDaysOfThisSessionAsALoginDoes(): void
    {
        $client = self::httpsClient();
        // The client keeps its kernel, so the clock stays in every service across requests. It starts at the real time:
        // the session's own timestamps come from PHP's time().
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $this->logIn($client, $this->createUserWithPassword('Raj Patel'));
        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $created = time() - 30 * 86400 + 3600;
        self::writeSessionCreatedTime($client, $created);
        self::assertSame($created, self::sessionMetadata($client)['c']);
        $beforeChange = time();

        $this->submitPasswordChange($client, self::PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/me/security', 302);
        self::assertGreaterThanOrEqual($beforeChange, self::sessionMetadata($client)['c']);
        // Two hours on, the old start would be past 30 days and the idle limit (8 h) is not reached; EndExpiredSession
        // reads now from the Clock.
        $clock->modify('+2 hours');
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function logOutOfAllDevicesEndsTheOtherBrowserAndThisOne(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $otherBrowser = self::anotherClient();
        $this->logIn($client, $user);
        $this->logIn($otherBrowser, $user);
        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $sessionIdBefore = self::sessionIdOf($client);

        $client->submitForm('Log out of all devices');

        self::assertResponseRedirects('/login', 302);
        self::assertNotSame($sessionIdBefore, self::sessionIdOf($client), 'this session was not ended on the server');
        $loginPage = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SecurityPageController::LOGGED_OUT_EVERYWHERE, $loginPage->filter('main [role="status"]')->text());
        self::assertLandsOnTheLoginPage($client);
        self::assertLandsOnTheLoginPage($otherBrowser);
    }

    #[Test]
    public function logOutOfAllDevicesLeavesOtherUsersIn(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Omar Haddad'));
        $miasBrowser = self::anotherClient();
        $this->logIn($miasBrowser, $this->createUserWithPassword('Mia Lund'));
        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Log out of all devices');

        self::assertResponseRedirects('/login', 302);
        $miasBrowser->request('GET', '/');
        self::assertSame(200, $miasBrowser->getResponse()->getStatusCode());
    }

    #[Test]
    public function everyActionIsRefusedWhenTheVoterDenies(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $hashBefore = $this->passwordHashOf($user);
        $stampBefore = $this->sessionStampOf($user);
        // Before the first request, while the voter is not built yet; the client keeps this kernel.
        self::getContainer()->set(OwnAccountVoter::class, new class extends Voter {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return AccountPermission::EDIT_OWN === $attribute;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        });

        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(403);
        // 403, not the 422 of the missing CSRF token: the voter is asked first.
        $client->request('POST', '/me/security/password', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_repeat' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/me/security/log-out-everywhere');
        self::assertResponseStatusCodeSame(403);

        self::assertSame($hashBefore, $this->passwordHashOf($user));
        self::assertSame($stampBefore, $this->sessionStampOf($user));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToTheLoginPageAndChangesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $stampBefore = $this->sessionStampOf($user);

        foreach ([['GET', '/me/security'], ['POST', '/me/security/password'], ['POST', '/me/security/log-out-everywhere']] as [$method, $path]) {
            $client->request($method, $path, ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_repeat' => self::NEW_PASSWORD]);
            self::assertResponseRedirects('/login', 302, $method.' '.$path);
        }

        self::assertSame($stampBefore, $this->sessionStampOf($user));
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenChangesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $otherBrowser = self::anotherClient();
        $this->logIn($client, $user);
        $this->logIn($otherBrowser, $user);
        $hashBefore = $this->passwordHashOf($user);
        $stampBefore = $this->sessionStampOf($user);

        foreach (['/me/security/password', '/me/security/log-out-everywhere'] as $path) {
            foreach ([[], ['_csrf_token' => 'forged']] as $csrf) {
                $client->request('POST', $path, ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_repeat' => self::NEW_PASSWORD, ...$csrf]);
                self::assertResponseStatusCodeSame(422, $path);
                self::assertSame(LoginController::FORM_EXPIRED, $client->getCrawler()->filter('main [role="alert"]')->text(), $path);
            }
        }

        self::assertSame($hashBefore, $this->passwordHashOf($user));
        self::assertSame($stampBefore, $this->sessionStampOf($user));
        foreach ([$client, $otherBrowser] as $browser) {
            $browser->request('GET', '/');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
        }
    }

    #[Test]
    public function thePageShowsBothSectionsWithTheMinimumLength(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Mia Lund'));

        $page = $client->request('GET', '/me/security');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Change password', 'Log out of all devices'], $page->filter('main h2')->each(static fn ($heading): string => $heading->text()));
        self::assertCount(2, $page->filter('main input[type="password"][minlength="12"]'));
        self::assertCount(1, $page->filter('main input[name="current_password"][type="password"]'));
        self::assertCount(0, $page->filter('main [role="alert"]'));
    }

    private function submitPasswordChange(KernelBrowser $client, string $currentPassword, string $newPassword, ?string $repeat = null): void
    {
        $client->request('GET', '/me/security');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submitForm('Change password', [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
            'new_password_repeat' => $repeat ?? $newPassword,
        ]);
    }

    private function submitLogin(KernelBrowser $client, User $user, string $password): void
    {
        $client->request('GET', '/login');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => $password]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    private static function assertLandsOnTheLoginPage(KernelBrowser $browser): void
    {
        $browser->request('GET', '/');
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    private function passwordHashOf(User $user): mixed
    {
        return $this->migrator()->fetchOne(self::HASH_QUERY, [$user->id()->toString()]);
    }

    private function sessionStampOf(User $user): mixed
    {
        return $this->migrator()->fetchOne('SELECT session_stamp FROM idn_user WHERE id = ?', [$user->id()->toString()]);
    }

    private static function sessionIdOf(KernelBrowser $client): ?string
    {
        return $client->getCookieJar()->get('__Host-pinround', '/', 'localhost')?->getValue();
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);
    }

    /**
     * A second browser on the same kernel, with its own cookies and so its own session.
     */
    private static function anotherClient(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->setServerParameters(['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);

        return $client;
    }

    private static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
