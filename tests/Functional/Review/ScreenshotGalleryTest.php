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

    #[Test]
    public function theGalleryShowsAScreenshotBeingProcessedAndOneThatCouldNotBe(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $pending = $crawler->filter('#gallery-pending-screenshot-heading ~ .screenshot-example figure#gallery-pending-screenshot');
        self::assertCount(1, $pending);
        self::assertSame('Processing…', $pending->filter('[role="status"].screenshot__placeholder')->text());
        self::assertCount(0, $pending->filter('img'));
        self::assertStringContainsString('#gallery-pending-screenshot-placeholder{max-width:800px;aspect-ratio:800 / 450}', (string) $client->getResponse()->getContent());
        $rejected = $crawler->filter('#gallery-rejected-screenshot-heading ~ .screenshot-example figure#gallery-rejected-screenshot');
        self::assertCount(1, $rejected);
        self::assertSame('We could not process this image. Try again.', $rejected->filter('.screenshot__placeholder--rejected')->text());
        self::assertCount(0, $rejected->filter('img'));
    }

    #[Test]
    public function theEditableExampleIsAFormWithThePinPlacerAndAnEmptyList(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $form = $crawler->filter('form#gallery-editable-screenshot-form[data-controller="pin-placer"]');
        self::assertCount(1, $form);
        self::assertSame('1000', $form->attr('data-pin-placer-note-max-value'));
        self::assertCount(1, $form->filter('figure#gallery-editable-screenshot [data-pin-placer-target="layer"]'));
        self::assertCount(0, $form->filter('button.pin'));
        self::assertCount(1, $form->filter('ol.pin-list[data-pin-placer-target="list"]'));
        self::assertSame('Add pin', $form->filter('button[data-pin-add]')->text());
        self::assertCount(1, $form->filter('[role="status"][data-pin-placer-target="status"]'));
        self::assertCount(1, $form->filter('[hidden][data-pin-placer-target="fields"]'));
        self::assertCount(0, $crawler->filter('#gallery-screenshot-heading ~ .screenshot-example [data-pin-remove]'));
    }

    #[Test]
    public function thePlacedPinsExampleCarriesPinsOneTwoAndFourFromTheServer(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $form = $crawler->filter('form#gallery-placed-screenshot-form[data-controller="pin-placer"]');
        self::assertCount(1, $form);
        self::assertSame(
            ['1', '2', '4'],
            $form->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('data-pin-number')),
        );
        self::assertSame('70.55', $form->filter('.pin--n2')->attr('data-x-percent'));
        self::assertSame(
            ['Remove pin 1', 'Remove pin 2', 'Remove pin 4'],
            $form->filter('[data-pin-remove]')->each(static fn (Crawler $button): string => (string) $button->attr('aria-label')),
        );
    }
}
