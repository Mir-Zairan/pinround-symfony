<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        self::assertSame(['Button', 'Chip', 'Segmented control', 'Tag', 'AI chip', 'Status pill', 'Switch', 'Avatar', 'Avatar stack', 'Card', 'Empty state', 'Toast', 'Dialog', 'Share this round?', 'Menu', 'Person picker', 'Show tickets of…', 'Give SHOP-12 to…', 'Your next step box', 'Your next step', 'Move to Needs input', 'Queue bar', 'Screenshot with pins', 'Screenshot with pins you place'], $crawler->filter('h2')->each(static fn ($heading): string => $heading->text()));
        // 4 variants x 2 sizes x (normal, disabled, link)
        // plus the one action button inside the empty-state example, the dialog's trigger, Cancel and Confirm, the
        // two person pickers' Cancel and the Assign one's "Give it to…", the next step box's verb, its "Move to another
        // step…" summary, Cancel and Move, and the two queue bars' Previous, Next ticket or Finish, and Stop, and the
        // editable screenshot's Add pin
        self::assertCount(42, $crawler->filter('main .btn'));
        self::assertCount(11, $crawler->filter('main a.btn[href]'));
        self::assertCount(4, $crawler->filter('main button.btn.btn-sm[disabled]'));
        self::assertCount(8, $crawler->filter('main button.btn.btn-text:not([disabled])'));
    }

    #[Test]
    public function theGalleryShowsEveryStateOfTheOtherComponents(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('#gallery-chip ~ .gallery-row button.chip[aria-pressed="true"]'));
        self::assertCount(1, $crawler->filter('#gallery-chip ~ .gallery-row button.chip[aria-pressed="false"]'));
        // 2 view groups + 3 team groups, each with exactly one pressed option, + 1 link group with one current page
        self::assertCount(6, $crawler->filter('main [role="group"][aria-label]'));
        self::assertCount(5, $crawler->filter('main .seg[aria-pressed="true"]'));
        self::assertSame(['List', 'Whose turn?', 'Lena', 'Omar', 'Mia'], $crawler->filter('main .seg[aria-pressed="true"]')->each(static fn ($option): string => $option->text()));
        self::assertSame(['List', 'Whose turn?'], $crawler->filter('main [aria-label="View as links"] a.seg[href]')->each(static fn ($link): string => $link->text()));
        self::assertSame(['List'], $crawler->filter('main a.seg[aria-current="page"]')->each(static fn ($link): string => $link->text()));
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
        self::assertSame(['Cancel', 'Confirm'], $crawler->filter('main dialog#gallery-dialog-demo .dialog__actions .btn')->each(static fn ($button): string => $button->text()));
        self::assertCount(1, $crawler->filter('main button[aria-haspopup="menu"][aria-expanded="false"][aria-controls="gallery-menu-list"]'));
        self::assertCount(1, $crawler->filter('main #gallery-menu-list[role="menu"][hidden]'));
        self::assertCount(3, $crawler->filter('main #gallery-menu-list [role="menuitem"]'));
    }

    /**
     * The real picker reads a team's people in a live request, which the server refuses for the page's made-up team,
     * so the gallery's dialog holds fixed rows and nothing in it talks to the server.
     */
    #[Test]
    public function theGalleryShowsThePersonPickerDialogWithSampleRowsAndSendsNoLiveRequest(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $picker = $crawler->filter('#gallery-person-picker ~ .gallery-row');
        self::assertSame([['Person ▾', 'false'], ['Omar', 'true']], $picker->filter('button.chip')->each(static fn (Crawler $chip): array => [$chip->text(), $chip->attr('aria-pressed')]));
        self::assertSame('dialog#open', $picker->filter('button.chip[aria-pressed="true"]')->attr('data-action'));
        $dialog = $picker->filter('dialog#gallery-person-picker-demo');
        self::assertSame('Show tickets of…', $dialog->filter('.dialog__title')->text());
        self::assertSame('Sorted by open tickets assigned to them.', $dialog->filter('.person-picker__sub')->text());
        self::assertSame('Find a person', $dialog->filter('input.person-picker__field[type="search"]')->attr('aria-label'));
        self::assertSame(
            [['Omar', '3 open', 'true'], ['Mia', '1 open', 'false'], ['Lena', '0 open', 'false']],
            $dialog->filter('button.pick')->each(static fn (Crawler $row): array => [$row->filter('.pick__name')->text(), $row->filter('.pick__side')->text(), $row->attr('aria-pressed')]),
        );
        self::assertCount(0, $dialog->filter('[data-person-picker-hint]'));
        self::assertSame(['Cancel'], $dialog->filter('.dialog__actions .btn')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(0, $picker->filter('[data-model], [data-controller~="person-picker"], [data-action*="live#"]'));
        self::assertCount(0, $crawler->filter('[data-live-name-value]'));
    }

    /**
     * The Assign mode's words and order: the ticket's key in the title, "N waiting" beside each person, fewest first.
     * Each row submits the sample's own form, which does nothing outside a dialog, so a pick never posts.
     */
    #[Test]
    public function theGalleryShowsTheAssignPersonPickerWithWaitingCountsFewestFirst(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $picker = $crawler->filter('#gallery-person-picker ~ .gallery-row');
        self::assertSame(['Give it to…'], $picker->filter('button.btn.btn-outline[aria-haspopup="dialog"][data-action="dialog#open"]')->each(static fn (Crawler $trigger): string => $trigger->text()));
        $dialog = $picker->filter('dialog#gallery-person-picker-assign-demo');
        self::assertCount(1, $dialog);
        self::assertSame('Give SHOP-12 to…', $dialog->filter('.dialog__title')->text());
        self::assertSame('The ticket moves to their list. Sorted by how much is already waiting on them.', $dialog->filter('.person-picker__sub')->text());
        self::assertSame(
            [['Mia', '0 waiting', 'submit'], ['Lena', '1 waiting', 'submit'], ['Omar', '3 waiting', 'submit']],
            $dialog->filter('button.pick')->each(static fn (Crawler $row): array => [$row->filter('.pick__name')->text(), $row->filter('.pick__side')->text(), $row->attr('type')]),
        );
        self::assertSame(['gallery-person-picker-give-form'], array_values(array_unique($dialog->filter('button.pick')->each(static fn (Crawler $row): ?string => $row->attr('form')))));
        self::assertSame('dialog', $crawler->filter('form#gallery-person-picker-give-form')->attr('method'));
        self::assertSame(['Cancel'], $dialog->filter('.dialog__actions .btn')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(0, $picker->filter('[data-model], [data-controller~="person-picker"], [data-action*="live#"]'));
    }

    /**
     * Lena, the lead, on a ticket in Testing: one outline verb, the rule note, the move list open with Testing pressed,
     * and the Needs input chip with its dialog. The dialog's Move posts to the real move route, which refuses it.
     */
    #[Test]
    public function theGalleryShowsTheNextStepBoxWithItsMoveListOpenAndTheNeedsInputDialog(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="styles/ticket-"]'));
        $box = $crawler->filter('#gallery-next-step-box ~ .gallery-next-step section.next-step-box');
        self::assertCount(1, $box);
        self::assertSame('Your next step', $box->filter('.next-step-box__label')->text());
        self::assertSame('Nothing for you now. Mia’s turn.', $box->filter('.next-step-box__sentence')->text());
        self::assertSame(
            [['/t/SHOP-12/move/put_on_hold', 'Put on hold', 'btn btn-outline']],
            $box->filter('.next-step-box__verbs form')->each(static fn (Crawler $form): array => [(string) $form->attr('action'), $form->filter('button')->text(), $form->filter('button')->attr('class')]),
        );
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $box->filter('.next-step-box__rule-note')->text());
        $moveList = $box->filter('details.next-step-box__move-to[open]');
        self::assertCount(1, $moveList);
        self::assertSame(
            [
                ['To do', ['Backlog', 'To do']],
                ['Doing', ['In progress']],
                ['Checking', ['Code review', 'Testing']],
                ['Waiting', ['Needs input', 'On hold']],
                ['Done', ['Done']],
            ],
            $moveList->filter('.next-step-box__group')->each(static fn (Crawler $group): array => [
                $group->filter('.next-step-box__group-label')->text(),
                $group->filter('button.chip')->each(static fn (Crawler $chip): string => $chip->text()),
            ]),
        );
        self::assertSame(['Testing'], $moveList->filter('button[aria-current="step"]')->each(static fn (Crawler $chip): string => $chip->text()));
        $dialog = $moveList->filter('[data-controller="waiting-on"] dialog');
        self::assertCount(1, $dialog);
        self::assertSame('Move to Needs input', $dialog->filter('.dialog__title')->text());
        self::assertSame(
            [['Sara · Reporter', ''], ['Omar · Assignee', null], ['Lena · Code reviewer', null], ['Mia · Tester', null], ['Someone else', null]],
            $dialog->filter('.waiting-on__choice')->each(static fn (Crawler $choice): array => [$choice->text(), $choice->filter('input[type="radio"]')->attr('checked')]),
        );
        self::assertSame(['Cancel', 'Move'], $dialog->filter('.dialog__actions .btn')->each(static fn (Crawler $button): string => $button->text()));
    }

    /**
     * The first ticket of a queue, with Previous off and Next ticket, and the last, with Previous on and Finish; both
     * with Stop. Their styles come from ticket.css, which the gallery links.
     */
    #[Test]
    public function theGalleryShowsTheQueueBarOnTheFirstAndTheLastTicket(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="styles/ticket-"]'));
        $bars = $crawler->filter('#gallery-queue-bar ~ nav.queue-bar');
        self::assertSame(
            [
                ['Queue, first ticket', 'Code reviews · 1 of 3', [['Previous', null, 'true'], ['Next ticket', '/t/SHOP-13', null], ['Stop', null, null]], ['/t/SHOP-12/queue/stop']],
                ['Queue, last ticket', 'Code reviews · 3 of 3', [['Previous', '/t/SHOP-13', null], ['Finish', null, null], ['Stop', null, null]], ['/tickets/queue/finish', '/t/SHOP-14/queue/stop']],
            ],
            $bars->each(static fn (Crawler $bar): array => [
                $bar->attr('aria-label'),
                $bar->filter('.queue-bar__line')->text(),
                $bar->filter('.btn')->each(static fn (Crawler $button): array => [$button->text(), $button->attr('href'), $button->attr('aria-disabled')]),
                $bar->filter('form')->each(static fn (Crawler $form): ?string => $form->attr('action')),
            ]),
        );
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
