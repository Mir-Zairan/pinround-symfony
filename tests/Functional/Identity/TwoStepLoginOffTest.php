<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AccountPermission;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\ConfirmItsYouController;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\SecurityPageController;
use App\Identity\UI\Voter\OwnAccountVoter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Test\Factories;

/**
 * Turning two-step login off on /me/security with the password and a code, through the real firewall over HTTPS
 * (ADR-0012). The client keeps its kernel, so the clock set in the container is the one every request reads; it runs
 * 7 minutes ahead of the wall clock. The password and code counters live on the state Valkey per user, and every test
 * has new users.
 */
final class TwoStepLoginOffTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;
    use TurnsOnTwoStepLogin;

    private const string ACTION = '/me/security/two-step/off';
    private const string TURN_OFF = 'Turn off two-step login';

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
    public function thePasswordAndAnAppCodeTurnItOffAndASetupThenStartsFromANewKey(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $page = $this->openSecurityPage();
        self::assertSame('on', $page->filter('main [data-two-step-state]')->attr('data-two-step-state'));
        $form = $page->filter('main form[action="'.self::ACTION.'"]');
        self::assertSame(['Password', 'Code from your app'], $form->filter('label')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSame('current-password', $form->filter('input[name="password"]')->attr('autocomplete'));
        self::assertSame('one-time-code', $form->filter('input[name="code"]')->attr('autocomplete'));
        self::assertStringContainsString('Admin pages need two-step login.', $form->text());
        self::assertNotNull(self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));

        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        self::assertResponseRedirects('/me/security', 302);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertFalse($this->hasRow($user));
        self::assertNull(self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        self::assertNull(self::sessionAttribute($this->client, 'identity.recent_auth_at'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(SecurityPageController::TWO_STEP_LOGIN_OFF, $page->filter('main [role="status"]')->text());
        self::assertSame('off', $page->filter('main [data-two-step-state]')->attr('data-two-step-state'));
        self::assertCount(0, $page->filter('main form[action="'.self::ACTION.'"]'));
        self::assertCount(1, $page->selectLink('Set up two-step login'));

        $this->client->request('GET', '/me/security/two-step');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Set up two-step login');
        self::assertResponseRedirects('/me/security/two-step', 302);
        $setupPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame($seed, str_replace(' ', '', $setupPage->filter('main .two-step__key')->text()));
    }

    #[Test]
    public function aRecoveryCodeTurnsItOff(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        [, $recoveryCodes] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->openCodePage($this->client, $user);
        $this->client->submitForm('Log in', ['code' => $recoveryCodes[0]]);
        self::assertResponseRedirects('/', 302);
        $this->openSecurityPage();

        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => $recoveryCodes[1]]);

        self::assertResponseRedirects('/me/security', 302);
        self::assertFalse($this->hasRow($user));
    }

    #[Test]
    public function aWrongPasswordLeavesItOnAndSpendsNoCode(): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openSecurityPage();
        $code = self::codeOf($seed, $this->clock);

        $this->client->submitForm(self::TURN_OFF, ['password' => 'not the password at all', 'code' => $code]);

        $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        self::assertSame('true', $this->client->getCrawler()->filter('main form[action="'.self::ACTION.'"] input[name="password"]')->attr('aria-invalid'));
        self::assertTrue($this->hasRow($user));
        self::assertNotNull(self::sessionAttribute($this->client, 'identity.two_step_confirmed_at'));
        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => $code]);
        self::assertResponseRedirects('/me/security', 302, 'the wrong password spent the code');
        self::assertFalse($this->hasRow($user));
    }

    #[Test]
    public function aWrongCodeOrTheCodeTheLoginUsedLeavesItOn(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        [$seed] = $this->turnOnTwoStepLogin($this->client, $this->clock, $user);
        $this->clock->sleep(30);
        $loginCode = self::codeOf($seed, $this->clock);
        $this->openCodePage($this->client, $user);
        $this->client->submitForm('Log in', ['code' => $loginCode]);
        self::assertResponseRedirects('/', 302);
        $this->openSecurityPage();

        foreach ([self::wrongCodeFor($seed, $this->clock), $loginCode] as $typedCode) {
            $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => $typedCode]);
            $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        }

        self::assertTrue($this->hasRow($user));
        self::assertSame('on', $this->client->getCrawler()->filter('main [data-two-step-state]')->attr('data-two-step-state'));
    }

    #[Test]
    public function theSixthPasswordCheckInFifteenMinutesIsRefusedEvenWhenRight(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openSecurityPage();

        for ($try = 1; $try <= 5; ++$try) {
            $this->client->submitForm(self::TURN_OFF, ['password' => 'wrong password number '.$try, 'code' => self::codeOf($seed, $this->clock)]);
            $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        }
        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        $this->assertRefusedWith('Too many tries. Wait 15 minutes.', 429);
        self::assertTrue($this->hasRow($user));
    }

    /**
     * The login took one code try; four wrong codes here make five, so the next code is refused while the password,
     * at its fifth check, still is not.
     */
    #[Test]
    public function theSixthCodeInFifteenMinutesIsRefusedEvenWhenRight(): void
    {
        $user = $this->createUserWithPassword('Lena Berg');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $this->openSecurityPage();

        for ($try = 1; $try <= 4; ++$try) {
            $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => self::wrongCodeFor($seed, $this->clock)]);
            $this->assertRefusedWith(ConfirmItsYouController::WRONG_PASSWORD_OR_CODE);
        }
        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);

        $this->assertRefusedWith('Too many tries. Wait 15 minutes.', 429);
        self::assertTrue($this->hasRow($user));
    }

    #[Test]
    public function aSecondPostAfterItIsOffLeadsBackToThePageAndTakesNoTry(): void
    {
        $user = $this->createUserWithPassword('Omar Haddad');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $page = $this->openSecurityPage();
        $csrfToken = (string) $page->filter('main form[action="'.self::ACTION.'"] input[name="_csrf_token"]')->attr('value');
        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => self::codeOf($seed, $this->clock)]);
        self::assertResponseRedirects('/me/security', 302);

        // Five wrong passwords would use up the password limiter if this one took a try.
        for ($try = 1; $try <= 6; ++$try) {
            $this->client->request('POST', self::ACTION, ['password' => 'wrong password number '.$try, 'code' => '123456', '_csrf_token' => $csrfToken]);
            self::assertResponseRedirects('/me/security', 302);
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Change password', ['current_password' => self::PASSWORD, 'new_password' => 'a much longer new passphrase', 'new_password_repeat' => 'a much longer new passphrase']);
        self::assertResponseRedirects('/me/security', 302);
    }

    #[Test]
    public function anAnonymousVisitorGoesToTheLoginPageAndNothingChanges(): void
    {
        $user = $this->createUserWithPassword('Mia Lund');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $user);

        $this->client->request('POST', self::ACTION, ['password' => self::PASSWORD, 'code' => '123456']);

        self::assertResponseRedirects('/login', 302);
        self::assertTrue($this->hasRow($user));
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenChangesNothingAndSpendsNoCode(): void
    {
        $user = $this->createUserWithPassword('Raj Patel');
        $seed = $this->logInWithACode($user);
        $this->clock->sleep(30);
        $page = $this->openSecurityPage();
        $code = self::codeOf($seed, $this->clock);

        foreach ([[], ['_csrf_token' => 'forged'], ['_csrf_token' => (string) $page->filter('main form[action="/me/security/password"] input[name="_csrf_token"]')->attr('value')]] as $fields) {
            $this->client->request('POST', self::ACTION, ['password' => self::PASSWORD, 'code' => $code, ...$fields]);
            $this->assertRefusedWith(LoginController::FORM_EXPIRED);
        }
        self::assertTrue($this->hasRow($user));

        $this->client->submitForm(self::TURN_OFF, ['password' => self::PASSWORD, 'code' => $code]);
        self::assertResponseRedirects('/me/security', 302, 'the refused posts spent the code');
    }

    #[Test]
    public function itTurnsOffOnlyTheLoggedInUsersTwoStepLogin(): void
    {
        $lena = $this->createUserWithPassword('Lena Berg');
        $omar = $this->createUserWithPassword('Omar Haddad');
        $this->turnOnTwoStepLogin($this->client, $this->clock, $omar);
        $seed = $this->logInWithACode($lena);
        $this->clock->sleep(30);
        $page = $this->openSecurityPage();
        $csrfToken = (string) $page->filter('main form[action="'.self::ACTION.'"] input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', self::ACTION.'?user_id='.$omar->id()->toString(), [
            'password' => self::PASSWORD,
            'code' => self::codeOf($seed, $this->clock),
            'user_id' => $omar->id()->toString(),
            '_csrf_token' => $csrfToken,
        ]);

        self::assertResponseRedirects('/me/security', 302);
        self::assertFalse($this->hasRow($lena));
        self::assertTrue($this->hasRow($omar));
    }

    #[Test]
    public function thePostIsRefusedWhenTheVoterDenies(): void
    {
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logIn($this->client, $user);
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

        $this->client->request('POST', self::ACTION, ['password' => self::PASSWORD, 'code' => '123456']);

        // 403, not the 422 of the missing CSRF token: the voter is asked first.
        self::assertResponseStatusCodeSame(403);
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

    private function openSecurityPage(): Crawler
    {
        $page = $this->client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    private function assertRefusedWith(string $message, int $status = 422): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($message, $this->client->getCrawler()->filter('main [role="alert"]#two-step-off-error')->text());
        self::assertCount(1, $this->client->getCrawler()->filter('main [role="alert"]'));
    }

    private function hasRow(User $user): bool
    {
        return false !== $this->migrator()->fetchOne('SELECT 1 FROM idn_mfa_secret WHERE user_id = ?', [$user->id()->toString()]);
    }
}
