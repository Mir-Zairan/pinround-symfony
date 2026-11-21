<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\Theme;
use App\Identity\UI\Controller\ProfileController;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class HomePageShellTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function theHomePageHasOneMainAndOneHeading(): void
    {
        $page = $this->openHome();

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('main'));
        self::assertCount(1, $page->filter('h1'));
        self::assertSame('All clear, Lena', $page->filter('h1')->text());
        self::assertSelectorTextSame('title', 'pinround');
        self::assertSame('en', $page->filter('html')->attr('lang'));
        self::assertNull($page->filter('html')->attr('data-theme'));
        self::assertSame('light dark', $page->filter('meta[name="color-scheme"]')->attr('content'));
    }

    #[Test]
    public function theHeaderOfAPersonWithoutRolesHoldsTheLogoAndHomeButNoReportButtonOrBell(): void
    {
        $page = $this->openHome();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/', $page->filter('header.site-header a.logo')->attr('href'));
        self::assertSame('pinround', trim($page->filter('header.site-header a.logo')->text()));
        self::assertCount(1, $page->filter('nav[aria-label="Main"]'));
        self::assertCount(1, $page->filter('nav[aria-label="Main"] > *'));
        $home = $page->filter('nav[aria-label="Main"] > a.nav');
        self::assertSame('Home', $home->text());
        self::assertSame('/', $home->attr('href'));
        self::assertSame('page', $home->attr('aria-current'));
        self::assertCount(0, $page->filter('header button.btn-tonal'));
        // The phone menu button is an icon button too, and everyone has it.
        self::assertCount(0, $page->filter('header button.bell__trigger'));
        self::assertSame(['Menu'], $page->filter('header button.icon-btn')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('Skip to main content', $page->filter('a.skip-link')->text());
        self::assertSame('#main', $page->filter('a.skip-link')->attr('href'));
        self::assertCount(1, $page->filter('main#main'));
    }

    #[Test]
    public function thePhoneMenuButtonControlsTheOneMainNavWhichStartsClosedByAClassNotByHidden(): void
    {
        $page = $this->openHome();
        self::assertResponseStatusCodeSame(200);

        $holder = $page->filter('header .site-header__menu[data-controller="main-menu"]');
        self::assertCount(1, $holder);
        self::assertSame('site-header__nav--closed', $holder->attr('data-main-menu-closed-class'));
        $button = $holder->filter('button[type="button"][aria-expanded="false"][aria-controls="main-nav"][data-main-menu-target="trigger"]');
        self::assertCount(1, $button);
        self::assertSame('Menu', trim($button->text()));

        // One nav for every width: the button opens the same MainMenu items, never a second list.
        self::assertCount(1, $page->filter('nav[aria-label="Main"]'));
        $nav = $holder->filter('nav#main-nav[aria-label="Main"][data-main-menu-target="list"]');
        self::assertCount(1, $nav);
        self::assertContains('site-header__nav--closed', explode(' ', (string) $nav->attr('class')));
        // [hidden] would hide it at every width (base.css), the desktop row included.
        self::assertNull($nav->attr('hidden'));
        self::assertSame(['Home'], $nav->filter('a.nav[data-main-menu-target="item"]')->each(static fn (Crawler $link): string => $link->text()));
    }

    #[Test]
    public function theAvatarOpensAMenuWithProfileSecurityApiTokensThemeAndLogOutInThatOrder(): void
    {
        $page = $this->openHome();
        self::assertResponseStatusCodeSame(200);

        $trigger = $page->filter('header .avatar-menu > button[aria-expanded="false"][aria-controls="avatar-menu-list"]');
        self::assertCount(1, $trigger);
        self::assertSame('Lena Berg', $trigger->filter('[role="img"]')->attr('title'));
        $menu = $page->filter('header #avatar-menu-list[hidden]');
        self::assertCount(1, $menu);
        $items = $menu->children()->each(static fn (Crawler $item): string => match ($item->nodeName()) {
            'a' => $item->text().' → '.$item->attr('href'),
            'form' => $item->filter('[role="group"]')->count() > 0
                ? (string) $item->filter('[role="group"]')->attr('aria-label').' → '.$item->attr('action')
                : $item->filter('button[type="submit"]')->text().' → '.$item->attr('action'),
            default => $item->nodeName(),
        });
        self::assertSame(['Profile → /me/profile', 'Security → /me/security', 'API tokens → /me/tokens', 'Theme → /me/theme', 'Log out → /logout'], $items);

        $themeForm = $menu->filter('form[action="/me/theme"][method="post"]');
        self::assertSame(array_values(ProfileController::THEME_OPTIONS), $themeForm->filter('button.seg')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame(array_map(static fn (Theme $theme): string => $theme->value, Theme::cases()), $themeForm->filter('button.seg')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['system'], $themeForm->filter('button.seg[aria-pressed="true"]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertNotSame('', $themeForm->filter('input[type="hidden"][name="_csrf_token"]')->attr('value'));
        self::assertNotSame('', $menu->filter('form[action="/logout"] input[type="hidden"][name="_csrf_token"]')->attr('value'));
    }

    #[Test]
    public function theToastRegionAndDialogRootExist(): void
    {
        $page = $this->openHome();

        $toastRegion = $page->filter('.toast-region');
        self::assertCount(1, $toastRegion);
        self::assertSame('status', $toastRegion->attr('role'));
        self::assertSame('polite', $toastRegion->attr('aria-live'));
        self::assertCount(1, $page->filter('#dialog-root'));
    }

    #[Test]
    public function theStylesheetsAndFontPreloadsAreLocalAssets(): void
    {
        $page = $this->openHome();

        $stylesheets = $page->filter('link[rel="stylesheet"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        // Each shell component links its own file, as the bell does; the page's own comes last.
        self::assertCount(8, $stylesheets);
        foreach (['tokens', 'fonts', 'base', 'shell', 'components', 'bell', 'local-ai', 'home'] as $position => $name) {
            self::assertMatchesRegularExpression('#^/assets/styles/'.$name.'-[0-9A-Za-z_-]+\.css$#', $stylesheets[$position]);
        }

        $fonts = $page->filter('link[rel="preload"][as="font"][type="font/woff2"][crossorigin]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertCount(2, $fonts);
        foreach ($fonts as $font) {
            self::assertMatchesRegularExpression('#^/assets/fonts/.+\.woff2$#', $font);
        }
    }

    #[Test]
    public function everyScriptSharesOneNonceAndThereAreNoInlineStylesOrHandlers(): void
    {
        $page = $this->openHome();

        $nonces = $page->filter('script')->each(static fn (Crawler $script): ?string => $script->attr('nonce'));
        self::assertNotEmpty($nonces);
        self::assertNotContains(null, $nonces);
        self::assertNotContains('', $nonces);
        self::assertCount(1, array_unique($nonces));

        self::assertCount(0, $page->filterXPath('//*[@style]'), 'style attributes are blocked by the CSP');
        self::assertCount(0, $page->filterXPath('//*[@*[starts-with(name(), "on")]]'), 'inline handlers are blocked by the CSP');
    }

    #[Test]
    public function noReferenceLeavesThisOrigin(): void
    {
        $page = $this->openHome();

        $references = $page->filter('[src], [href]')->each(static fn (Crawler $element): string => ($element->attr('src') ?? '').' '.($element->attr('href') ?? ''));
        self::assertNotEmpty($references);
        foreach ($references as $reference) {
            self::assertDoesNotMatchRegularExpression('#(https?:)?//#i', $reference);
        }

        self::assertStringNotContainsString('es-module-shims', (string) $this->client()->getResponse()->getContent());
    }

    #[Test]
    public function aThemeOfLightOrDarkIsPrintedOnTheHtmlElement(): void
    {
        self::assertSame('dark', $this->renderHomeWithTheme('dark')->filter('html')->attr('data-theme'));
        self::assertSame('light', $this->renderHomeWithTheme('light')->filter('html')->attr('data-theme'));
    }

    #[Test]
    public function anUnknownThemeIsNotPrinted(): void
    {
        self::assertNull($this->renderHomeWithTheme('purple')->filter('html')->attr('data-theme'));
    }

    private ?KernelBrowser $browser = null;

    private function renderHomeWithTheme(string $theme): Crawler
    {
        $this->client(); // boots the kernel so the container exists; no request is needed
        $twig = static::getContainer()->get('twig');

        return new Crawler($twig->render('@Web/base.html.twig', ['theme' => $theme]));
    }

    private function client(): KernelBrowser
    {
        return $this->browser ??= static::createClient();
    }

    private function openHome(): Crawler
    {
        $this->logIn($this->client(), $this->createUserWithPassword('Lena Berg'));

        return $this->client()->request('GET', '/');
    }
}
