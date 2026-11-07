<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AccountPermission;
use App\Identity\Domain\User;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\ProfileController;
use App\Identity\UI\Voter\OwnAccountVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Test\Factories;

/**
 * /me/profile and the avatar menu's /me/theme through the real firewall, over HTTPS (FR-ID-07). Each test has new
 * users; the logins through the form come from a random IP each, so the login counters do not refuse them.
 */
final class ProfilePageTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private const string PROFILE_QUERY = 'SELECT name, time_zone, theme FROM idn_user WHERE id = ?';

    #[Test]
    public function thePageShowsTheSavedProfile(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Mia Lund'));

        $page = $client->request('GET', '/me/profile');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Profile', $page->filter('main h1')->text());
        self::assertSame('Mia Lund', $page->filter('input[name="name"]')->attr('value'));
        self::assertSame(['Europe/Berlin'], $page->filter('select[name="time_zone"] option[selected]')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame(\DateTimeZone::listIdentifiers(), $page->filter('select[name="time_zone"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['system'], $page->filter('input[name="theme"][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame(['System', 'Light', 'Dark'], $page->filter('fieldset label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertCount(0, $page->filter('main [role="alert"], .profile__error'));
    }

    #[Test]
    public function anOldTimeZoneNameStaysSelectableAndSelected(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $this->migrator()->executeStatement("UPDATE idn_user SET time_zone = 'US/Pacific' WHERE id = ?", [$user->id()->toString()]);
        $this->forgetLoadedUsers();
        $this->logIn($client, $user);

        $page = $client->request('GET', '/me/profile');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['US/Pacific'], $page->filter('select[name="time_zone"] option[selected]')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame('US/Pacific', $page->filter('select[name="time_zone"] option')->first()->attr('value'));
    }

    #[Test]
    public function savingChangesTheNameTimeZoneAndThemeAndKeepsTheUserLoggedIn(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $otherBrowser = self::anotherClient();
        $this->logIn($client, $user);
        $this->logIn($otherBrowser, $user);
        $client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Save', ['name' => '  Lena Lund  ', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark']);

        self::assertResponseRedirects('/me/profile', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(ProfileController::PROFILE_SAVED, $page->filter('main [role="status"]')->text());
        self::assertSame(['name' => 'Lena Lund', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark'], $this->storedProfileOf($user));
        self::assertSame('Lena Lund', $page->filter('input[name="name"]')->attr('value'));
        self::assertSame('LL', $page->filter('header.site-header [role="img"]')->text());
        self::assertSame('dark', $page->filter('html')->attr('data-theme'));
        $otherBrowser->request('GET', '/');
        self::assertSame(200, $otherBrowser->getResponse()->getStatusCode(), 'a profile change logged the other browser out');
    }

    /**
     * @return iterable<string, array{array{name: string, time_zone: string, theme: string}, list<string>}>
     */
    public static function refusedProfiles(): iterable
    {
        yield 'blank name' => [['name' => '   ', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark'], [ProfileController::NAME_BLANK]];
        yield 'time zone not in the list' => [['name' => 'Omar Said', 'time_zone' => 'Mars/Olympus', 'theme' => 'dark'], [ProfileController::TIME_ZONE_UNKNOWN]];
        yield 'offset instead of a time zone' => [['name' => 'Omar Said', 'time_zone' => '+02:00', 'theme' => 'dark'], [ProfileController::TIME_ZONE_UNKNOWN]];
        yield 'unknown theme' => [['name' => 'Omar Said', 'time_zone' => 'Asia/Kolkata', 'theme' => 'purple'], [ProfileController::THEME_UNKNOWN]];
        yield 'all three wrong' => [['name' => '', 'time_zone' => 'Mars/Olympus', 'theme' => 'purple'], [ProfileController::NAME_BLANK, ProfileController::TIME_ZONE_UNKNOWN, ProfileController::THEME_UNKNOWN]];
    }

    /**
     * @param array{name: string, time_zone: string, theme: string} $fields
     * @param list<string>                                          $expectedErrors
     */
    #[Test]
    #[DataProvider('refusedProfiles')]
    public function aWrongFieldIsShownBesideItAndNothingIsSaved(array $fields, array $expectedErrors): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $before = $this->storedProfileOf($user);
        $page = $client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);
        $csrfToken = (string) $page->filter('form[action="/me/profile"] input[name="_csrf_token"]')->attr('value');

        $page = $client->request('POST', '/me/profile', [...$fields, '_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($expectedErrors, $page->filter('.profile__error')->each(static fn (Crawler $error): string => $error->text()));
        foreach ($page->filter('.profile__error') as $error) {
            self::assertInstanceOf(\DOMElement::class, $error);
            self::assertCount(1, $page->filter(\sprintf('[aria-describedby="%s"]', $error->getAttribute('id'))), 'the error is not tied to its field');
        }
        self::assertSame($fields['name'], $page->filter('input[name="name"]')->attr('value'), 'the typed name is lost');
        self::assertSame($before, $this->storedProfileOf($user));
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenChangesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logIn($client, $user);
        $before = $this->storedProfileOf($user);

        foreach ([[], ['_csrf_token' => 'forged']] as $csrf) {
            $page = $client->request('POST', '/me/profile', ['name' => 'Sara Lund', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark', ...$csrf]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(LoginController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());

            $client->request('POST', '/me/theme', ['theme' => 'dark', ...$csrf]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(LoginController::FORM_EXPIRED, $client->getCrawler()->filter('main [role="alert"]')->text());
        }

        self::assertSame($before, $this->storedProfileOf($user));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToTheLoginPageAndChangesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $before = $this->storedProfileOf($user);

        foreach ([['GET', '/me/profile'], ['POST', '/me/profile'], ['POST', '/me/theme']] as [$method, $path]) {
            $client->request($method, $path, ['name' => 'Raj Lund', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark']);
            self::assertResponseRedirects('/login', 302, $method.' '.$path);
        }

        self::assertSame($before, $this->storedProfileOf($user));
    }

    #[Test]
    public function everyActionIsRefusedWhenTheVoterDenies(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $before = $this->storedProfileOf($user);
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

        foreach ([['GET', '/me/profile'], ['POST', '/me/profile'], ['POST', '/me/theme']] as [$method, $path]) {
            // 403, not the 422 of the missing CSRF token: the voter is asked first.
            $client->request($method, $path, ['name' => 'Omar Lund', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark']);
            self::assertResponseStatusCodeSame(403, $method.' '.$path);
        }

        self::assertSame($before, $this->storedProfileOf($user));
    }

    #[Test]
    public function theThemeSurvivesLogoutAndLogin(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $this->submitLogin($client, $user);
        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($home->filter('html')->attr('data-theme'));

        $this->pickTheme($client, 'dark');
        self::assertResponseRedirects('/', 302);
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log out');
        self::assertResponseRedirects('/login', 302);
        $this->submitLogin($client, $user);

        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('dark', $home->filter('html')->attr('data-theme'));
        self::assertSame(['dark'], $home->filter('.avatar-menu__theme button[aria-pressed="true"]')->each(static fn (Crawler $button): string => (string) $button->attr('value')));

        $this->pickTheme($client, 'system');
        self::assertResponseRedirects('/', 302);
        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($home->filter('html')->attr('data-theme'));
        self::assertSame('system', $this->storedProfileOf($user)['theme']);
    }

    #[Test]
    public function theGalleryThemeInTheAddressWinsOverTheSavedOne(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $this->migrator()->executeStatement("UPDATE idn_user SET theme = 'dark' WHERE id = ?", [$user->id()->toString()]);
        $this->forgetLoadedUsers();
        $this->logIn($client, $user);

        $gallery = $client->request('GET', '/_gallery?theme=light');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('light', $gallery->filter('html')->attr('data-theme'));

        $gallery = $client->request('GET', '/_gallery');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('dark', $gallery->filter('html')->attr('data-theme'));
    }

    #[Test]
    public function theThemeSwitchLeadsBackToThePageItWasUsedOn(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $this->logIn($client, $user);
        $client->request('GET', '/me/security?tab=password');
        self::assertResponseStatusCodeSame(200);

        $this->pickTheme($client, 'light');

        self::assertResponseRedirects('/me/security?tab=password', 302);
        self::assertSame('light', $this->storedProfileOf($user)['theme']);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('light', $page->filter('html')->attr('data-theme'));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function referersThatLeaveTheSite(): iterable
    {
        // An empty value, because the test browser otherwise sends the last page it showed.
        yield 'no referer' => [null];
        yield 'another site' => ['https://evil.example/me/security'];
        yield 'another site with this path' => ['https://evil.example/'];
        yield 'this host over plain http' => ['http://localhost/me/security'];
        yield 'this host on another port' => ['https://localhost:8443/me/security'];
        yield 'protocol-relative path on this host' => ['https://localhost//evil.example/x'];
        yield 'backslash path on this host' => ['https://localhost/\\evil.example/x'];
        yield 'not a URL' => ['javascript:alert(1)'];
    }

    #[Test]
    #[DataProvider('referersThatLeaveTheSite')]
    public function theThemeSwitchGoesHomeWhenTheRefererIsNotAPageOfThisSite(?string $referer): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $form = $client->getCrawler()->filter('form[action="/me/theme"]')->form(['theme' => 'dark']);

        $client->request('POST', '/me/theme', $form->getValues(), [], ['HTTP_REFERER' => $referer ?? '']);

        self::assertResponseRedirects('/', 302);
        self::assertSame('dark', $this->storedProfileOf($user)['theme']);
    }

    #[Test]
    public function anUnknownThemeIsRefusedOnTheProfilePage(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logIn($client, $user);
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $this->pickTheme($client, 'purple');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([ProfileController::THEME_UNKNOWN], $client->getCrawler()->filter('.profile__error')->each(static fn (Crawler $error): string => $error->text()));
        self::assertSame('system', $this->storedProfileOf($user)['theme']);
    }

    /**
     * Posts the avatar menu's theme form of the page the client shows, with its fields as the theme-switch script sends them.
     */
    private function pickTheme(KernelBrowser $client, string $theme): void
    {
        $form = $client->getCrawler()->filter('form[action="/me/theme"]')->form(['theme' => $theme]);
        $client->request('POST', '/me/theme', $form->getValues(), [], ['HTTP_REFERER' => $client->getRequest()->getUri()]);
    }

    private function submitLogin(KernelBrowser $client, User $user): void
    {
        $client->request('GET', '/login');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/', parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedProfileOf(User $user): array
    {
        $profile = $this->migrator()->fetchAssociative(self::PROFILE_QUERY, [$user->id()->toString()]);
        self::assertIsArray($profile);

        return $profile;
    }

    /**
     * The factory's user stays in the entity manager the first request shares; a row changed behind it must be read again.
     */
    private function forgetLoadedUsers(): void
    {
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();
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
