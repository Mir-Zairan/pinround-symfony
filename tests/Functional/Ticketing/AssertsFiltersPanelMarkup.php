<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use Symfony\Component\DomCrawler\Crawler;

/**
 * The markup _filters_panel.html.twig gives the tickets list and the whose-turn view, which tickets.css lays out: a
 * Filters button naming one panel that holds the project rail and the toolbar, closed by a class and not by [hidden]
 * (base.css hides [hidden] at every width, and from 600px the rail and the toolbar must show), and the panel between the
 * heading and the list, so Tab meets the heading's view switch, then the toolbar, then the list, as the page shows them.
 */
trait AssertsFiltersPanelMarkup
{
    private static function assertFiltersPanelFollowsTheHeadingAndStartsClosedByAClass(Crawler $page): void
    {
        self::assertSame(
            ['tickets__header', 'tickets__filters', 'tickets__list-frame'],
            $page->filter('.tickets-page')->children()->each(static fn (Crawler $part): string => explode(' ', (string) $part->attr('class'))[0]),
            'The heading comes first, then the rail and the toolbar, then the list.',
        );
        $header = $page->filter('.tickets-page > .tickets__header');
        self::assertSame(
            ['tickets__heading', 'seg-group'],
            $header->children()->each(static fn (Crawler $part): string => explode(' ', (string) $part->attr('class'))[0]),
            'The List / Whose turn? switch sits beside the heading, not under the count.',
        );
        self::assertCount(1, $header->filter('.tickets__heading > h1.tickets__title + p.tickets__total'));
        self::assertSame(['List', 'Whose turn?'], $header->filter('.tickets__view[role="group"][aria-label="View"] > a.seg')->each(static fn (Crawler $link): string => $link->text()));

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
