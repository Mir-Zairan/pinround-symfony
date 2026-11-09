<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RecentAuth;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\ConfirmItsYouController;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\RecentAuthRedirects;
use App\Tests\Fixtures\Http\RecentAuthClassProbeController;
use App\Tests\Fixtures\Http\RecentAuthProbeController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * RequiresRecentAuth on the test action /_test/recent-auth, and "Confirm it's you" at /me/confirm, through the real
 * firewall over HTTPS (ADR-0012). The client keeps its kernel, so the clock set in the container is the one every
 * request reads; it runs 7 minutes ahead of the wall clock. The password and code counters live on the state Valkey
 * per user, and every test has new users.
 */
final class ConfirmItsYouTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;
    use TurnsOnTwoStepLogin;

    private const string SENSITIVE_ACTION = '/_test/recent-auth';
    private const string CONFIRM_FOR_THE_ACTION = '/me/confirm?return=/_test/recent-auth';
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
    public function aRouteProtectedByRecentAuthAsksAgainAfterTenMinutes(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(9 * 60);

        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(RecentAuthProbeController::OPENED, $this->client->getResponse()->getContent());

        $this->clock->sleep(60 + 1);
        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextSame('title', "Confirm it's you · pinround");
        $card = $page->filter('main section.card');
        self::assertSame("Confirm it's you", $card->filter('h1')->text());
        self::assertSame(['Password', 'Code from your app'], $card->filter('label')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSame('current-password', $card->filter('input[name="password"]')->attr('autocomplete'));
        self::assertSame('one-time-code', $card->filter('input[name="code"]')->attr('autocomplete'));
        self::assertSame(self::CONFIRM_FOR_THE_ACTION, $card->filter('form')->attr('action'));
        self::assertCount(0, $card->filter('[role="alert"]'));
        $sessionIdBefore = $this->sessionId();

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects(self::SENSITIVE_ACTION, 302);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($sessionIdBefore, $this->sessionId(), 'the confirmation gave the session a new id');
        $lenaNow = ['user_id' => $user->id()->toString(), 'at' => $this->clock->now()->getTimestamp()];
        self::assertSame($lenaNow, self::sessionAttribute($this->client, 'identity.recent_auth_at'));
        self::assertSame($lenaNow, self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(RecentAuthProbeController::OPENED, $this->client->getResponse()->getContent());
    }

    #[Test]
    public function theMarkOnAControllerClassGuardsItsActions(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $seed = $this->logInWithACode($user);
        $this->client->request('GET', '/_test/recent-auth/class');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(RecentAuthClassProbeController::OPENED, $this->client->getResponse()->getContent());
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);

        $this->client->request('GET', '/_test/recent-auth/class');

        self::assertResponseRedirects('/me/confirm?return=/_test/recent-auth/class', 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects('/_test/recent-auth/class', 302);
    }

    #[Test]
    public function aSubRequestToAMarkedActionGetsTheRedirectInsteadOfTheAction(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logInWithACode($user);
        $this->client->request('GET', '/_test/recent-auth/forward');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(RecentAuthProbeController::OPENED, $this->client->getResponse()->getContent());
        $this->clock->sleep(RecentAuth::WINDOW_SECONDS + 1);

        $this->client->request('GET', '/_test/recent-auth/forward');

        self::assertResponseRedirects('/me/confirm?return=/_test/recent-auth/forward', 302);
        self::assertStringNotContainsString(RecentAuthProbeController::OPENED, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aLoginWithThePasswordOnlyIsNotRecentEvenAtOnce(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->logIn($this->client, $user);
        $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        self::writeSessionAttribute($this->client, 'identity.recent_auth_at', ['user_id' => $user->id()->toString(), 'at' => $this->clock->now()->getTimestamp()]);

        $this->client->request('GET', self::SENSITIVE_ACTION);

        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);
    }

    #[Test]
    public function aPostWithoutProofReturnsToThePageOfItsForm(): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->logIn($this->client, $user);

        $this->client->request('POST', self::SENSITIVE_ACTION, server: ['HTTP_REFERER' => 'https://localhost/me/security']);
        self::assertResponseRedirects('/me/confirm?return=/me/security', 302);

        $this->client->request('POST', self::SENSITIVE_ACTION, server: ['HTTP_REFERER' => 'https://evil.example/me/security']);
        self::assertResponseRedirects('/me/confirm?return=/', 302);
    }

    #[Test]
    public function aUserWithoutTwoStepLoginIsSentToSetUpAndThenGetsAContinueLinkBack(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        $this->logIn($this->client, $user);

        $this->client->request('GET', self::SENSITIVE_ACTION);

        self::assertResponseRedirects('/me/security/two-step', 302);
        $setupPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(RecentAuthRedirects::TURN_ON_TWO_STEP_LOGIN_FIRST, $setupPage->filter('main [role="status"]')->text());
        self::assertSame(self::SENSITIVE_ACTION, self::sessionAttribute($this->client, RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY));

        $this->client->submitForm('Set up two-step login');
        self::assertResponseRedirects('/me/security/two-step', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('main [role="status"]'), 'the message showed twice');
        $seed = str_replace(' ', '', $page->filter('main .two-step__key')->text());
        $this->client->submitForm('Turn on', ['code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseStatusCodeSame(200);

        $codesPage = $this->client->getCrawler();
        self::assertSame(['Continue'], $codesPage->filter('main .two-step__codes ~ div a')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame(self::SENSITIVE_ACTION, $codesPage->selectLink('Continue')->attr('href'));
        self::assertNull(self::sessionAttribute($this->client, RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY));
        // The setup proved the code, but this login never asked for the password.
        $this->client->clickLink('Continue');
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);
    }

    #[Test]
    public function confirmItsYouWithoutTwoStepLoginSendsToSetUpWithTheReturnPathKept(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logIn($this->client, $user);

        $this->client->request('GET', self::CONFIRM_FOR_THE_ACTION);

        self::assertResponseRedirects('/me/security/two-step', 302);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(self::SENSITIVE_ACTION, self::sessionAttribute($this->client, RecentAuthRedirects::AFTER_TWO_STEP_SETUP_KEY));
    }

    #[Test]
    public function theRecoveryCodesPageOfASetupNobodySentShowsISavedThem(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $this->logIn($this->client, $user);
        $this->client->request('GET', '/me/security/two-step');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Set up two-step login');
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $seed = str_replace(' ', '', $page->filter('main .two-step__key')->text());

        $this->client->submitForm('Turn on', ['code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseStatusCodeSame(200);
        $links = $this->client->getCrawler()->filter('main .two-step__codes ~ div a');
        self::assertSame(['I saved them'], $links->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame('/me/security', $links->attr('href'));
    }

    #[Test]
    public function aWrongPasswordIsRefusedWithoutSpendingTheCode(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openConfirmPage();
        $code = self::codeOf($seed, $this->clock);

        $this->client->submitForm('Confirm', ['password' => 'not the password at all', 'code' => $code]);

        $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        self::assertSame('true', $this->client->getCrawler()->filter('main input[name="password"]')->attr('aria-invalid'));
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $code]);
        self::assertResponseRedirects(self::SENSITIVE_ACTION, 302, 'the wrong password spent the code');
    }

    #[Test]
    public function aWrongCodeIsRefusedWithTheSameMessageAndRecordsNothing(): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(11 * 60);
        $this->openConfirmPage();
        $timesBefore = [self::sessionAttribute($this->client, 'identity.recent_auth_at'), self::sessionAttribute($this->client, 'identity.two_step_confirmed_at')];

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::wrongCodeFor($seed, $this->clock)]);

        $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        self::assertSame($timesBefore, [self::sessionAttribute($this->client, 'identity.recent_auth_at'), self::sessionAttribute($this->client, 'identity.two_step_confirmed_at')]);
        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);
    }

    #[Test]
    public function aRecoveryCodeConfirmsOnce(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        [, $recoveryCodes] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($this->client, $user);
        $this->client->submitForm('Log in', ['code' => $recoveryCodes[0]]);
        self::assertResponseRedirects('/', 302);
        $this->clock->sleep(11 * 60);
        $this->openConfirmPage();

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $recoveryCodes[1]]);
        self::assertResponseRedirects(self::SENSITIVE_ACTION, 302);

        $this->clock->sleep(11 * 60);
        $this->openConfirmPage();
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $recoveryCodes[1]]);
        $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
    }

    #[Test]
    public function theCodeTheLoginUsedIsRefusedHere(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $code = self::codeOf($seed, $this->clock);
        $this->openCodePage($this->client, $user);
        $this->client->submitForm('Log in', ['code' => $code]);
        self::assertResponseRedirects('/', 302);
        $this->openConfirmPage();

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $code]);

        $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
    }

    #[Test]
    public function theSixthPasswordCheckInFifteenMinutesIsRefusedEvenWhenRight(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openConfirmPage();

        for ($try = 1; $try <= 5; ++$try) {
            $this->client->submitForm('Confirm', ['password' => 'wrong password number '.$try, 'code' => self::codeOf($seed, $this->clock)]);
            $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        }
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        $this->assertRefusedWith('Too many tries. Wait 15 minutes.', 429);
        $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Change password', ['current_password' => self::PASSWORD, 'new_password' => 'a much longer new passphrase', 'new_password_repeat' => 'a much longer new passphrase']);
        self::assertResponseStatusCodeSame(429, 'the security page gave the password more tries');
    }

    /**
     * The login took one code try; four wrong codes here make five, so the next code is refused while the password,
     * at its fifth check, still is not.
     */
    #[Test]
    public function theSixthCodeInFifteenMinutesIsRefusedEvenWhenRight(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openConfirmPage();

        for ($try = 1; $try <= 4; ++$try) {
            $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::wrongCodeFor($seed, $this->clock)]);
            $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        }
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        $this->assertRefusedWith('Too many tries. Wait 15 minutes.', 429);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function returnPathsOfOtherSites(): iterable
    {
        yield 'a URL' => ['https://evil.example/'];
        yield 'a URL without a scheme' => ['//evil.example/'];
        yield 'a backslash a browser reads as a slash' => ['/\\evil.example/'];
        yield 'a path no route has' => ['/no/such/page'];
    }

    #[Test]
    #[DataProvider('returnPathsOfOtherSites')]
    public function aReturnPathOutsideThisAppLeadsHome(string $returnPath): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->client->request('GET', '/me/confirm?'.http_build_query(['return' => $returnPath]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('/me/confirm?return=/', $this->client->getCrawler()->filter('main form')->attr('action'));

        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function anAnonymousVisitorGoesToTheLoginPage(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, self::CONFIRM_FOR_THE_ACTION, ['password' => self::PASSWORD, 'code' => '123456']);
            self::assertResponseRedirects('/login', 302, $method);
        }

        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenIsRefusedAndSpendsNoCode(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(11 * 60);
        $page = $this->openConfirmPage();
        $code = self::codeOf($seed, $this->clock);

        foreach ([[], ['_csrf_token' => 'forged']] as $fields) {
            $this->client->request('POST', self::CONFIRM_FOR_THE_ACTION, ['password' => self::PASSWORD, 'code' => $code, ...$fields]);
            $this->assertRefusedWith(LoginController::FORM_EXPIRED);
        }
        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);

        $this->client->request('POST', self::CONFIRM_FOR_THE_ACTION, ['password' => self::PASSWORD, 'code' => $code, '_csrf_token' => $page->filter('main form input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects(self::SENSITIVE_ACTION, 302, 'the refused posts spent the code');
    }

    /**
     * Times stored for another user, as a person who logged in after another in this browser session could find them,
     * never count, and a confirmation records the logged-in user's own.
     */
    #[Test]
    public function theTimesOfAnotherUserInTheSessionDoNotCount(): void
    {
        $lena = $this->createUserWithPassword('Lena Berg');
        $omar = $this->createUserWithPassword('Omar Haddad');
        $seed = $this->logInWithACode($lena);
        $omarNow = ['user_id' => $omar->id()->toString(), 'at' => $this->clock->now()->getTimestamp()];
        self::writeSessionAttribute($this->client, 'identity.recent_auth_at', $omarNow);
        self::writeSessionAttribute($this->client, 'identity.two_step_confirmed_at', $omarNow);

        $this->client->request('GET', self::SENSITIVE_ACTION);
        self::assertResponseRedirects(self::CONFIRM_FOR_THE_ACTION, 302);
        $this->clock->sleep(30);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects(self::SENSITIVE_ACTION, 302);
        $lenaNow = ['user_id' => $lena->id()->toString(), 'at' => $this->clock->now()->getTimestamp()];
        self::assertSame($lenaNow, self::sessionAttribute($this->client, 'identity.recent_auth_at'));
        self::assertSame($lenaNow, self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
    }

    /**
     * Turns two-step login on and logs in with a code from the app, at the clock's time after a 30-second step.
     *
     * @return string the seed
     */
    private function logInWithACode(User $user): string
    {
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $this->openCodePage($this->client, $user);
        $this->client->submitForm('Log in', ['code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects('/', 302);

        return $seed;
    }

    private function openConfirmPage(): Crawler
    {
        $page = $this->client->request('GET', self::CONFIRM_FOR_THE_ACTION);
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    private function assertRefusedWith(string $message, int $status = 422): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($message, $this->client->getCrawler()->filter('main [role="alert"]#confirm-error')->text());
    }

    private function sessionId(): ?string
    {
        return $this->client->getCookieJar()->get(self::SESSION_COOKIE, '/', 'localhost')?->getValue();
    }
}
