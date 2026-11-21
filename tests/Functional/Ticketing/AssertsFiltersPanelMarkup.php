<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The markup _filters_panel.html.twig gives the tickets list and the whose-turn view, which tickets.css lays out: a
 * Filters button naming one panel that holds the project rail and the toolbar, closed by a class and not by [hidden]
 * (base.css hides [hidden] at every width, and from 600px the rail and the toolbar must show), and the panel before the
 * heading, so Tab meets the rail and the toolbar where a wide screen shows them.
 */
trait AssertsFiltersPanelMarkup
{
    private static function assertFiltersPanelComesFirstAndStartsClosedByAClass(Crawler $page): void
    {
        self::assertSame(
            ['tickets__filters', 'tickets__header', 'tickets__list-frame'],
            $page->filter('.tickets-page')->children()->each(static fn (Crawler $part): string => explode(' ', (string) $part->attr('class'))[0]),
            'The rail and the toolbar come before the heading, and the heading before the list.',
        );

        $holder = $page->filter('.tickets-page > .tickets__filters[data-controller="menu"]');
        self::assertCount(1, $holder);
        self::assertSame('tickets__filters-panel--closed', $holder->attr('data-menu-closed-class'));
        self::assertSame(
            ['button', 'div'],
            $holder->children()->each(static fn (Crawler $part): string => $part->nodeName()),
            'The button comes before the panel it opens.',
        );

        $button = $holder->filter('button[aria-expanded="false"][aria-controls="tickets-filters-panel"][data-menu-target="trigger"]');
        self::assertCount(1, $button);
        self::assertSame('Filters', trim($button->text()));

        $panel = $holder->filter('#tickets-filters-panel[data-menu-target="list"]');
        self::assertCount(1, $panel);
        self::assertContains('tickets__filters-panel--closed', explode(' ', (string) $panel->attr('class')));
        self::assertNull($panel->attr('hidden'));
        self::assertCount(1, $panel->filter('nav.project-rail'));
        self::assertCount(1, $panel->filter('.ticket-filters'));
        self::assertCount(1, $page->filter('nav.project-rail'), 'No second rail outside the panel.');
        self::assertCount(1, $page->filter('.ticket-filters'), 'No second toolbar outside the panel.');
    }
}
