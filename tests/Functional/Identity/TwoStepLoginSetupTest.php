<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AccountPermission;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\TwoStepLoginController;
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
 * Turning two-step login on from /me/security through the real firewall, over HTTPS (FR-ID-03, ADR-0012). The client
 * keeps its kernel, so the clock set in the container is the one every request reads; it runs 10 minutes ahead of
 * the wall clock, so a code computed from the wall clock would be 20 steps off. It cannot run further: the session's
 * own timestamps come from PHP's time(), and EndExpiredSession compares them with the Clock.
 */
final class TwoStepLoginSetupTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;
    use TurnsOnTwoStepLogin;

    private const string PAGE = '/me/security/two-step';
    private const string RECOVERY_CODE_FORMAT = '/^[0-9a-hjkmnp-tv-z]{5}-[0-9a-hjkmnp-tv-z]{5}$/';

    #[Test]
    public function anAnonymousVisitorIsSentToTheLoginPageAndNothingIsStored(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');

        foreach ([['GET', self::PAGE], ['POST', self::PAGE.'/start'], ['POST', self::PAGE.'/confirm']] as [$method, $path]) {
            $client->request($method, $path, ['code' => '123456']);
            self::assertResponseRedirects('/login', 302, $method.' '.$path);
        }

        self::assertFalse($this->rowOf($user));
    }

    #[Test]
    public function turningItOnShowsAServerDrawnQrCodeAndTheKeyAndItStaysOffUntilACodeIsTyped(): void
    {
        [$client, , $user] = $this->loggedInWithClock('Lena Berg');

        $security = $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('off', $security->filter('main [data-two-step-state]')->attr('data-two-step-state'));
        $setupPage = $client->click($security->selectLink('Set up two-step login')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(0, $setupPage->filter('main img'));
        self::assertFalse($this->rowOf($user));

        $client->submitForm('Set up two-step login');

        self::assertResponseRedirects(self::PAGE, 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $source = (string) $page->filter('main img.two-step__qr')->attr('src');
        self::assertStringStartsWith('data:image/svg+xml;base64,', $source);
        $svg = (string) base64_decode(substr($source, \strlen('data:image/svg+xml;base64,')), true);
        self::assertMatchesRegularExpression('/<svg\b[^>]*viewBox="0 0 200 200"/', $svg);
        self::assertSame('QR code for your authenticator app', $page->filter('main img.two-step__qr')->attr('alt'));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{4}( [A-Z2-7]{4}){7}$/', self::manualKeyOn($page));
        self::assertSame(0, $page->filter('main [role="alert"]')->count());
        self::assertNull($page->filter('main input[name="code"]')->attr('aria-invalid'));
        self::assertSame('two-step-code-hint', $page->filter('main input[name="code"]')->attr('aria-describedby'));
        $row = $this->rowOf($user);
        self::assertIsArray($row);
        self::assertNull($row['confirmed_at']);
        self::assertSame('{}', $row['recovery_code_hashes']);
        self::assertStringNotContainsString(self::seedOn($page), $row['secret_enc_hex'], 'the seed is stored as hex');
        self::assertStringNotContainsString(bin2hex(self::seedOn($page)), $row['secret_enc_hex'], 'the seed is stored in plain bytes');
        $security = $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('off', $security->filter('main [data-two-step-state]')->attr('data-two-step-state'));
        self::assertCount(1, $security->selectLink('Finish setting up two-step login'));
    }

    #[Test]
    public function aNewQrCodeReplacesTheKeyAndTheOldKeysCodeNoLongerWorks(): void
    {
        [$client, $clock] = $this->loggedInWithClock('Omar Haddad');
        $firstPage = $this->turnOn($client);
        $firstSeed = self::seedOn($firstPage);

        $client->submitForm('Get a new QR code');

        self::assertResponseRedirects(self::PAGE, 302);
        $secondPage = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame($firstSeed, self::seedOn($secondPage));
        $client->submitForm('Turn on', ['code' => self::codeOf($firstSeed, $clock)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(TwoStepLoginController::WRONG_CODE, $client->getCrawler()->filter('main [role="alert"]')->text());
    }

    #[Test]
    public function aWrongCodeShowsAPlainErrorAndLeavesItOff(): void
    {
        [$client, $clock, $user] = $this->loggedInWithClock('Mia Lund');
        $page = $this->turnOn($client);
        $seed = self::seedOn($page);

        $client->submitForm('Turn on', ['code' => self::wrongCodeFor($seed, $clock)]);

        self::assertResponseStatusCodeSame(422);
        $page = $client->getCrawler();
        self::assertSame(TwoStepLoginController::WRONG_CODE, $page->filter('main form[action="/me/security/two-step/confirm"] [role="alert"]#two-step-code-error')->text());
        self::assertCount(1, $page->filter('main [role="alert"]'));
        $codeField = $page->filter('main input[name="code"]');
        self::assertSame('true', $codeField->attr('aria-invalid'));
        self::assertSame('two-step-code-error two-step-code-hint', $codeField->attr('aria-describedby'));
        self::assertSame($seed, self::seedOn($page), 'the key changed after a wrong code');
        self::assertCount(1, $page->filter('main img.two-step__qr'));
        self::assertCount(0, $page->filter('main .two-step__codes'));
        $row = $this->rowOf($user);
        self::assertIsArray($row);
        self::assertNull($row['confirmed_at']);
        self::assertSame('{}', $row['recovery_code_hashes']);
        self::assertNull(self::sessionAttribute($client, 'identity.two_step_confirmed_at'));
    }

    #[Test]
    public function theRightCodeShowsTenRecoveryCodesOnceAndThePagesThenSayItIsOn(): void
    {
        [$client, $clock, $user] = $this->loggedInWithClock('Sara Holm');
        $awaitingPage = $this->turnOn($client);
        $seed = self::seedOn($awaitingPage);
        $code = self::codeOf($seed, $clock);
        $sessionIdBefore = $client->getCookieJar()->get('__Host-pinround', '/', 'localhost')?->getValue();
        self::assertNull(self::sessionAttribute($client, 'identity.two_step_confirmed_at'));

        $client->submitForm('Turn on', ['code' => $code]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($clock->now()->getTimestamp(), self::sessionAttribute($client, 'identity.two_step_confirmed_at'));
        self::assertSame($sessionIdBefore, $client->getCookieJar()->get('__Host-pinround', '/', 'localhost')?->getValue(), 'the session got a new id');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $codesPage = $client->getCrawler();
        self::assertSame('Two-step login is on', $codesPage->filter('main h1')->text());
        $recoveryCodes = $codesPage->filter('main .two-step__codes li')->each(static fn (Crawler $item): string => $item->text());
        self::assertCount(10, $recoveryCodes);
        self::assertSame($recoveryCodes, array_values(array_unique($recoveryCodes)));
        foreach ($recoveryCodes as $recoveryCode) {
            self::assertMatchesRegularExpression(self::RECOVERY_CODE_FORMAT, $recoveryCode);
        }
        self::assertStringNotContainsString($seed, (string) $client->getResponse()->getContent());
        $row = $this->rowOf($user);
        self::assertIsArray($row);
        self::assertNotNull($row['confirmed_at']);
        self::assertSame(intdiv($clock->now()->getTimestamp(), 30), $row['last_used_step']);
        self::assertSame(10, $row['recovery_code_count']);
        foreach ($recoveryCodes as $recoveryCode) {
            self::assertStringNotContainsString($recoveryCode, $row['recovery_code_hashes']);
        }

        $page = $client->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Two-step login is on.', $page->filter('main [role="status"]')->text());
        self::assertCount(0, $page->filter('main img'));
        self::assertCount(0, $page->filter('main .two-step__key'));
        self::assertCount(0, $page->filter('main form'));
        self::assertNoneOfThe($recoveryCodes, $seed, (string) $client->getResponse()->getContent());

        // Sending the form again, as a reload of the codes page would, shows neither codes nor key, and proves nothing.
        $confirmedAt = $clock->now()->getTimestamp();
        $clock->sleep(40);
        $client->request('POST', self::PAGE.'/confirm', ['code' => self::codeOf($seed, $clock), '_csrf_token' => self::csrfTokenOf($awaitingPage, self::PAGE.'/confirm')]);
        self::assertResponseRedirects(self::PAGE, 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertNoneOfThe($recoveryCodes, $seed, (string) $client->getResponse()->getContent());
        self::assertSame($row['recovery_code_hashes'], $this->rowOf($user)['recovery_code_hashes'] ?? null);
        self::assertSame($confirmedAt, self::sessionAttribute($client, 'identity.two_step_confirmed_at'));

        $security = $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('on', $security->filter('main [data-two-step-state]')->attr('data-two-step-state'));
        self::assertCount(0, $security->selectLink('Set up two-step login'));
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenChangesNothing(): void
    {
        [$client, $clock, $user] = $this->loggedInWithClock('Lena Berg');

        foreach ([[], ['_csrf_token' => 'forged']] as $csrf) {
            $client->request('POST', self::PAGE.'/start', $csrf);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(LoginController::FORM_EXPIRED, $client->getCrawler()->filter('main [role="alert"]')->text());
        }
        self::assertFalse($this->rowOf($user));

        $awaitingPage = $this->turnOn($client);
        $seed = self::seedOn($awaitingPage);
        $encryptedSeedBefore = $this->rowOf($user)['secret_enc_hex'] ?? null;
        foreach ([[], ['_csrf_token' => 'forged'], ['_csrf_token' => self::csrfTokenOf($awaitingPage, self::PAGE.'/start')]] as $csrf) {
            $client->request('POST', self::PAGE.'/confirm', ['code' => self::codeOf($seed, $clock), ...$csrf]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(LoginController::FORM_EXPIRED, $client->getCrawler()->filter('main [role="alert"]')->text());
            self::assertCount(0, $client->getCrawler()->filter('main .two-step__codes'));
        }
        foreach ([[], ['_csrf_token' => 'forged']] as $csrf) {
            $client->request('POST', self::PAGE.'/start', $csrf);
            self::assertResponseStatusCodeSame(422);
        }

        $row = $this->rowOf($user);
        self::assertIsArray($row);
        self::assertNull($row['confirmed_at']);
        self::assertSame($encryptedSeedBefore, $row['secret_enc_hex'], 'a refused start replaced the seed');
    }

    #[Test]
    public function everyActionIsRefusedWhenTheVoterDenies(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
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

        foreach ([['GET', self::PAGE], ['POST', self::PAGE.'/start'], ['POST', self::PAGE.'/confirm']] as [$method, $path]) {
            // 403, not the 422 of the missing CSRF token: the voter is asked first.
            $client->request($method, $path, ['code' => '123456']);
            self::assertResponseStatusCodeSame(403, $method.' '.$path);
        }

        self::assertFalse($this->rowOf($user));
    }

    /**
     * @return array{KernelBrowser, MockClock, User}
     */
    private function loggedInWithClock(string $name): array
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $clock = new MockClock();
        $clock->modify('+10 minutes');
        self::getContainer()->set('clock', $clock);
        $user = $this->createUserWithPassword($name);
        $this->logIn($client, $user);

        return [$client, $clock, $user];
    }

    private function turnOn(KernelBrowser $client): Crawler
    {
        $client->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Set up two-step login');
        self::assertResponseRedirects(self::PAGE, 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    /**
     * The CSRF token of the form that posts to $action; it stays valid for the session after the page is gone.
     */
    private static function csrfTokenOf(Crawler $page, string $action): string
    {
        return (string) $page->filter(\sprintf('main form[action="%s"] input[name="_csrf_token"]', $action))->attr('value');
    }

    /**
     * @return array{confirmed_at: string|null, recovery_code_hashes: string, recovery_code_count: int, last_used_step: int|null, secret_enc_hex: string}|false
     *
     * @phpstan-impure
     */
    private function rowOf(User $user): array|false
    {
        /** @var array{confirmed_at: string|null, recovery_code_hashes: string, recovery_code_count: int, last_used_step: int|null, secret_enc_hex: string}|false $row */
        $row = $this->migrator()->fetchAssociative(
            'SELECT confirmed_at, recovery_code_hashes::text AS recovery_code_hashes, cardinality(recovery_code_hashes) AS recovery_code_count,'
            ." last_used_step, encode(secret_enc, 'hex') AS secret_enc_hex FROM idn_mfa_secret WHERE user_id = ?",
            [$user->id()->toString()],
        );

        return $row;
    }

    private static function manualKeyOn(Crawler $page): string
    {
        return $page->filter('main .two-step__key')->text();
    }

    private static function seedOn(Crawler $page): string
    {
        return str_replace(' ', '', self::manualKeyOn($page));
    }

    /**
     * @param list<string> $recoveryCodes
     */
    private static function assertNoneOfThe(array $recoveryCodes, string $seed, string $content): void
    {
        self::assertStringNotContainsString($seed, $content);
        foreach ($recoveryCodes as $recoveryCode) {
            self::assertStringNotContainsString($recoveryCode, $content);
        }
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
    }
}
