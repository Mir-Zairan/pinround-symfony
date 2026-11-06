<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ComponentGalleryTest extends WebTestCase
{
    #[Test]
    public function theGalleryShowsEveryButtonVariantAndSize(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Button', $crawler->filter('h2')->text());
        // 4 variants x 2 sizes x (normal, disabled, link)
        self::assertCount(24, $crawler->filter('main .btn'));
        self::assertCount(8, $crawler->filter('main a.btn[href]'));
        self::assertCount(4, $crawler->filter('main button.btn.btn-sm[disabled]'));
        self::assertCount(2, $crawler->filter('main button.btn.btn-text:not([disabled])'));
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
