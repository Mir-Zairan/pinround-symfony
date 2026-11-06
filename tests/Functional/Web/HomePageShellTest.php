<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomePageShellTest extends WebTestCase
{
    #[Test]
    public function theHomePageHasOneMainAndOneHeading(): void
    {
        $page = $this->openHome();

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('main'));
        self::assertCount(1, $page->filter('h1'));
        self::assertSame('Home', $page->filter('h1')->text());
        self::assertSelectorTextSame('title', 'pinround');
        self::assertSame('en', $page->filter('html')->attr('lang'));
        self::assertNull($page->filter('html')->attr('data-theme'));
        self::assertSame('light dark', $page->filter('meta[name="color-scheme"]')->attr('content'));
    }

    #[Test]
    public function theHeaderHoldsTheLogoNavigationReportButtonAndBell(): void
    {
        $page = $this->openHome();

        self::assertSame('/', $page->filter('header.site-header a.logo')->attr('href'));
        self::assertSame('pinround', trim($page->filter('header.site-header a.logo')->text()));
        self::assertCount(1, $page->filter('nav[aria-label="Main"]'));
        $home = $page->filter('nav[aria-label="Main"] a.nav');
        self::assertCount(1, $home);
        self::assertSame('Home', $home->text());
        self::assertSame('/', $home->attr('href'));
        self::assertSame('page', $home->attr('aria-current'));
        self::assertSame('Report a problem', $page->filter('header button.btn-tonal')->text());
        self::assertSame('Pings', $page->filter('header button.icon-btn')->attr('aria-label'));
        self::assertSame('Skip to main content', $page->filter('a.skip-link')->text());
        self::assertSame('#main', $page->filter('a.skip-link')->attr('href'));
        self::assertCount(1, $page->filter('main#main'));
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
        self::assertCount(4, $stylesheets);
        foreach (['tokens', 'fonts', 'base', 'shell'] as $position => $name) {
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

        return new Crawler($twig->render('@Web/home.html.twig', ['theme' => $theme]));
    }

    private function client(): KernelBrowser
    {
        return $this->browser ??= static::createClient();
    }

    private function openHome(): Crawler
    {
        return $this->client()->request('GET', '/');
    }
}
