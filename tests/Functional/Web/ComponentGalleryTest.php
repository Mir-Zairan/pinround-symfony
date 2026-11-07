<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ComponentGalleryTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function theGalleryShowsEveryButtonVariantAndSize(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame(['Button', 'Chip', 'Segmented control', 'Tag', 'AI chip', 'Status pill', 'Switch', 'Avatar', 'Avatar stack', 'Card', 'Empty state', 'Toast', 'Dialog', 'Share this round?', 'Menu'], $crawler->filter('h2')->each(static fn ($heading): string => $heading->text()));
        // 4 variants x 2 sizes x (normal, disabled, link)
        // plus the one action button inside the empty-state example and the dialog's trigger, Cancel and Confirm
        self::assertCount(28, $crawler->filter('main .btn'));
        self::assertCount(9, $crawler->filter('main a.btn[href]'));
        self::assertCount(4, $crawler->filter('main button.btn.btn-sm[disabled]'));
        self::assertCount(3, $crawler->filter('main button.btn.btn-text:not([disabled])'));
    }

    #[Test]
    public function theGalleryShowsEveryStateOfTheOtherComponents(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('main button.chip[aria-pressed="true"]'));
        self::assertCount(1, $crawler->filter('main button.chip[aria-pressed="false"]'));
        // 2 view groups + 3 team groups, each with exactly one pressed option
        self::assertCount(5, $crawler->filter('main [role="group"][aria-label]'));
        self::assertCount(5, $crawler->filter('main .seg[aria-pressed="true"]'));
        self::assertSame(['List', 'Whose turn?', 'Lena', 'Omar', 'Mia'], $crawler->filter('main .seg[aria-pressed="true"]')->each(static fn ($option): string => $option->text()));
        self::assertCount(2, $crawler->filter('main .tag'));
        self::assertCount(3, $crawler->filter('main .aichip'));
        foreach (['todo', 'doing', 'checking', 'waiting', 'done'] as $group) {
            self::assertCount(1, $crawler->filter('main .pill.pill-'.$group));
        }
        self::assertStringContainsString('Waiting · question to Mia', $crawler->filter('main .pill-waiting')->text());
        self::assertCount(1, $crawler->filter('main button.sw[role="switch"][aria-checked="true"]'));
        self::assertCount(1, $crawler->filter('main button.sw[role="switch"][aria-checked="false"]'));
    }

    #[Test]
    public function theGalleryShowsAvatarsStacksCardEmptyStatesAndToast(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(4, $crawler->filter('#gallery-avatar ~ .gallery-row .av'));
        self::assertCount(2, $crawler->filter('#gallery-avatar ~ .gallery-row .av-lg'));
        // stacks of 1, 4 and 7: 1 + 4 + 4 avatars, one "+3" item
        self::assertCount(3, $crawler->filter('main .stack'));
        self::assertCount(9, $crawler->filter('main .stack .av:not(.av-more)'));
        self::assertCount(1, $crawler->filter('main .stack .av-more'));
        self::assertSame('+3', $crawler->filter('main .stack .av-more')->text());
        self::assertCount(1, $crawler->filter('main section.card h3'));
        self::assertCount(2, $crawler->filter('main .empty-state'));
        self::assertCount(1, $crawler->filter('main .empty-state a.btn'));
        self::assertCount(1, $crawler->filter('main .toast'));
    }

    #[Test]
    public function theGalleryShowsADialogDemoAndAMenuDemo(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Open dialog', $crawler->filter('main [data-controller="dialog"] > button[data-action="dialog#open"]')->text());
        self::assertSame(['Cancel', 'Confirm'], $crawler->filter('main dialog .dialog__actions .btn')->each(static fn ($button): string => $button->text()));
        self::assertCount(1, $crawler->filter('main button[aria-haspopup="menu"][aria-expanded="false"][aria-controls="gallery-menu-list"]'));
        self::assertCount(1, $crawler->filter('main #gallery-menu-list[role="menu"][hidden]'));
        self::assertCount(3, $crawler->filter('main #gallery-menu-list [role="menuitem"]'));
    }

    #[Test]
    public function theGalleryLinksItsOwnStylesheetAndHomeDoesNot(): void
    {
        $client = self::createClient();
        $gallery = $client->request('GET', '/_gallery');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $gallery->filter('link[rel="stylesheet"][href*="styles/gallery-"]'));

        $this->logIn($client, $this->createUserWithPassword('Lena Berg'));
        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $home->filter('link[href*="gallery"]'));
    }

    #[Test]
    public function aThemeQueryPicksTheTheme(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery?theme=dark');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('dark', $crawler->filter('html')->attr('data-theme'));
    }

    #[Test]
    public function aLightThemeQueryPicksTheLightTheme(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery?theme=light');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('light', $crawler->filter('html')->attr('data-theme'));
    }

    #[Test]
    public function anUnknownThemeIsIgnored(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery?theme=neon');

        self::assertResponseStatusCodeSame(200);
        self::assertNull($crawler->filter('html')->attr('data-theme'));
    }
}
