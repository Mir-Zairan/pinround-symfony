<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ScreenshotGalleryTest extends WebTestCase
{
    #[Test]
    public function theGalleryShowsTheScreenshotWithItsTextPinsAndList(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="styles/review-"]'));
        $shot = $crawler->filter('#gallery-screenshot-heading ~ .screenshot-example figure.screenshot');
        self::assertCount(1, $shot);
        self::assertSame('Checkout page', $shot->filter('.screenshot__title')->text());
        self::assertNotSame('', $shot->filter('.screenshot__summary')->text());
        $names = $shot->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label'));
        self::assertCount(3, $names);
        self::assertSame('Pin 1: The address field is cut off.', $names[0]);
        self::assertCount(1, $shot->filter('.pin--selected'));
        self::assertCount(1, $shot->filter('.pin--ai'));
        self::assertCount(3, $crawler->filter('#gallery-screenshot-heading ~ .screenshot-example .pin-list__item'));
        self::assertStringContainsString('left:28.00%;top:30.00%', (string) $client->getResponse()->getContent());
    }
}
