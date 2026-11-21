<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review\UI\Component;

use App\Media\Api\MediaImageStatus;
use App\Review\UI\Component\ScreenshotEditor;
use App\Review\UI\PinView;
use App\Review\UI\ScreenshotEditorItem;
use App\Review\UI\ScreenshotFieldNames;
use App\Review\UI\ScreenshotView;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The screenshot editor as the report draft page and Report a problem would fill it: four screenshots of a draft of
 * Acme Foods' project SHOP with pins and notes, one of the report form, and none.
 */
final class ScreenshotEditorTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function withFourScreenshotsOnlyTheFirstShowsAndEachHasItsThumbnail(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots());

        $panels = $editor->filter('[role="tabpanel"][data-screenshot-editor-target="panel"]');
        self::assertCount(4, $panels);
        self::assertSame([null, '', '', ''], $panels->each(static fn (Crawler $panel): ?string => $panel->attr('hidden')));
        self::assertCount(1, $panels->eq(0)->filter('img.screenshot__image[src="/media/shop-cart.png"]'));
        self::assertSame([null, 'lazy', 'lazy', 'lazy'], $panels->filter('img.screenshot__image')->each(static fn (Crawler $image): ?string => $image->attr('loading')), 'The shown picture loads at once, the hidden ones when shown.');

        $tabs = $editor->filter('[role="tablist"] button[role="tab"][data-screenshot-editor-target="tab"]');
        self::assertCount(4, $tabs);
        self::assertSame(['true', 'false', 'false', 'false'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('aria-selected')));
        self::assertSame(['0', '-1', '-1', '-1'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('tabindex')));
        self::assertSame(
            ['Screenshot 1 of 4', 'Screenshot 2 of 4', 'Screenshot 3 of 4', 'Screenshot 4 of 4'],
            $tabs->each(static fn (Crawler $tab): string => $tab->text()),
        );
        self::assertSame('draft-shots-panel-3', $tabs->eq(2)->attr('aria-controls'));
        self::assertSame('draft-shots-tab-3', $panels->eq(2)->attr('aria-labelledby'));
        self::assertSame('draft-shots-panel-3', $panels->eq(2)->attr('id'));
        self::assertSame('/media/shop-receipt.png', $tabs->eq(2)->filter('img')->attr('src'));
        self::assertSame('', $tabs->eq(2)->filter('img')->attr('alt'), 'The tab text names the thumbnail.');
    }

    #[Test]
    public function everyPinAndItsNoteIsPrintedOnEveryScreenshotShownOrNot(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots());
        $panels = $editor->filter('[role="tabpanel"]');

        self::assertSame(['Pin 1: total counted twice', 'Pin 2: wrong currency'], $panels->eq(0)->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')));
        self::assertSame(['total counted twice', 'wrong currency'], $panels->eq(0)->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertSame(['button cut off'], $panels->eq(1)->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertCount(0, $panels->eq(2)->filter('.pin-list'), 'A screenshot without pins prints no empty list.');
        self::assertSame(['', 'date in the past', 'name missing'], $panels->eq(3)->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertSame(['1', '2', '3'], $panels->eq(3)->filter('.pin-list__number')->each(static fn (Crawler $number): string => $number->text()));
        self::assertSame('Note for pin 2', $panels->eq(3)->filter('textarea.pin-list__note')->eq(1)->attr('aria-label'));
        self::assertCount(0, $editor->filter('[data-pin-remove]'), 'A draft keeps its pins; only their notes change.');
    }

    #[Test]
    public function aDraftScreenshotPostsTheFieldNamesTheReportDraftPagePostsToday(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots());
        $fourth = $editor->filter('[role="tabpanel"]')->eq(3);

        self::assertSame('Opening hours', $fourth->filter('input[name="screenshots[3][title]"]')->attr('value'));
        self::assertSame('The page says closed on a work day.', $fourth->filter('textarea[name="screenshots[3][summary]"]')->text());
        self::assertSame(
            ['screenshots[3][pins][0]', 'screenshots[3][pins][1]', 'screenshots[3][pins][2]'],
            $fourth->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => (string) $note->attr('name')),
        );
        $remove = $fourth->filter('input[type="hidden"][name="screenshots[3][remove]"]');
        self::assertSame('1', $remove->attr('value'));
        self::assertSame('', $remove->attr('disabled'), 'A kept screenshot posts no remove field, as the unticked box did.');
        self::assertSame('120', $fourth->filter('input[name="screenshots[3][title]"]')->attr('maxlength'));
        self::assertSame('600', $fourth->filter('textarea[name="screenshots[3][summary]"]')->attr('maxlength'));
        self::assertNull($fourth->filter('textarea[name="screenshots[3][pins][1]"]')->attr('maxlength'), 'The browser counts UTF-16 units, the server code points; the controller cuts.');
        self::assertSame('1000', $editor->filter('[data-controller="screenshot-editor"]')->attr('data-screenshot-editor-note-max-value'));
        self::assertSame(['Picture title · optional', 'What this picture shows · optional'], $fourth->filter('label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame('draft-shot-4-title', $fourth->filter('label')->eq(0)->attr('for'));
        self::assertCount(1, $fourth->filter('input#draft-shot-4-title'));
        self::assertCount(0, $editor->filter('[data-controller~="pin-placer"]'), 'Pins of a draft keep their spots.');
    }

    #[Test]
    public function aScreenshotOfTheReportFormPostsItsFieldsAndPlacesPinsWithThePinPlacer(): void
    {
        $item = new ScreenshotEditorItem(self::view('report-shot', '/media/shop-cart.png', [new PinView(1, 20.0, 30.0, 'total counted twice')], 'Cart'), ScreenshotFieldNames::ofReportForm());

        $panel = $this->renderEditor([$item], 'report-shots')->filter('[role="tabpanel"]');

        self::assertSame('Cart', $panel->filter('input[name="screenshot_title"]')->attr('value'));
        self::assertCount(1, $panel->filter('textarea[name="screenshot_summary"]'));
        $placer = $panel->filter('.pin-placer[data-controller="pin-placer"]');
        self::assertCount(1, $placer);
        self::assertSame('What is wrong here?', $placer->attr('data-pin-placer-note-placeholder-value'));
        self::assertStringContainsString('click->pin-placer#onClick', (string) $placer->attr('data-action'));
        self::assertCount(1, $placer->filter('[data-pin-placer-target="layer"] button.pin'));
        self::assertSame('pins[1][note]', $placer->filter('ol[data-pin-placer-target="list"] textarea.pin-list__note')->attr('name'));
        self::assertCount(1, $placer->filter('div[hidden][data-pin-placer-target="fields"]'));
        self::assertSame('Add pin', $placer->filter('button[data-pin-add]')->text());
        self::assertCount(0, $panel->filter('input[name$="[remove]"]'), 'The report form removes its picture through its upload, not a field.');
    }

    #[Test]
    public function addAndRemoveAreButtonsTheControllerHandles(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots());

        $add = $editor->filter('button.screenshot-editor__add');
        self::assertCount(1, $add);
        self::assertSame('Add screenshot', $add->text());
        self::assertSame('button', $add->attr('type'));
        self::assertSame('screenshot-editor#add', $add->attr('data-action'));
        $removeButtons = $editor->filter('[role="tabpanel"] button.screenshot-editor__remove');
        self::assertCount(4, $removeButtons);
        self::assertSame(['Remove screenshot'], array_values(array_unique($removeButtons->each(static fn (Crawler $button): string => $button->text()))));
        self::assertSame('screenshot-editor#remove', $removeButtons->eq(0)->attr('data-action'));
        self::assertSame('button', $removeButtons->eq(0)->attr('type'));
        self::assertSame('screenshot-editor', $editor->filter('#draft-shots')->attr('data-controller'));
    }

    #[Test]
    public function withoutAddOnlyTheThumbnailsShow(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots(), canAdd: false);

        self::assertCount(0, $editor->filter('.screenshot-editor__add'));
        self::assertCount(4, $editor->filter('button[role="tab"]'));
    }

    #[Test]
    public function aScreenshotPostedAsRemovedShowsAsRemovedWithItsFieldOnAndCanBeKept(): void
    {
        $screenshots = self::draftScreenshots();
        $screenshots[1] = new ScreenshotEditorItem($screenshots[1]->view, $screenshots[1]->fieldNames, isRemoved: true);

        $editor = $this->renderEditor($screenshots);
        $second = $editor->filter('[role="tabpanel"]')->eq(1);
        $first = $editor->filter('[role="tabpanel"]')->eq(0);

        $remove = $second->filter('input[name="screenshots[1][remove]"]');
        self::assertSame('1', $remove->attr('value'));
        self::assertNull($remove->attr('disabled'));
        self::assertNull($second->filter('.screenshot-editor__removed')->attr('hidden'));
        self::assertSame('Removed. It goes when you save.', $second->filter('.screenshot-editor__removed p')->text());
        self::assertSame('Keep it', $second->filter('.screenshot-editor__removed button')->text());
        self::assertSame('screenshot-editor#keep', $second->filter('.screenshot-editor__removed button')->attr('data-action'));
        self::assertSame('', $second->filter('.screenshot-editor__kept')->attr('hidden'));
        self::assertSame('Screenshot 2 of 4 Removed', $editor->filter('button[role="tab"]')->eq(1)->text());
        self::assertCount(1, $editor->filter('button[role="tab"].screenshot-editor__thumb--removed'));

        self::assertSame('', $first->filter('input[name="screenshots[0][remove]"]')->attr('disabled'));
        self::assertSame('', $first->filter('.screenshot-editor__removed')->attr('hidden'));
        self::assertNull($first->filter('.screenshot-editor__kept')->attr('hidden'));
        self::assertSame('Screenshot 1 of 4', $editor->filter('button[role="tab"]')->eq(0)->text());
    }

    #[Test]
    public function aScreenshotStillProcessingHasAPlaceholderThumbnailAndSaysSo(): void
    {
        $pending = new ScreenshotView('draft-shot-1', null, 800, 450, 'Screenshot 1 of the report', 'Cart', status: MediaImageStatus::Pending);

        $editor = $this->renderEditor([new ScreenshotEditorItem($pending, ScreenshotFieldNames::ofReportDraft(0))]);

        self::assertCount(0, $editor->filter('button[role="tab"] img'));
        self::assertCount(1, $editor->filter('button[role="tab"] .screenshot-editor__thumb-placeholder'));
        self::assertSame('Processing…', $editor->filter('[role="tabpanel"] .screenshot__placeholder')->text());
    }

    #[Test]
    public function withoutScreenshotsItOffersToAddOneAndShowsNoTitleOrSummary(): void
    {
        $editor = $this->renderEditor([]);

        self::assertSame(ScreenshotEditor::EMPTY_MESSAGE, $editor->filter('.empty-state p')->text());
        self::assertSame('Add screenshot', $editor->filter('.empty-state button.screenshot-editor__add')->text());
        self::assertCount(0, $editor->filter('input, textarea'));
        self::assertCount(0, $editor->filter('[role="tablist"]'));
    }

    #[Test]
    public function withoutScreenshotsAndWithoutAddItOnlySaysThereAreNone(): void
    {
        $editor = $this->renderEditor([], canAdd: false);

        self::assertSame(ScreenshotEditor::NO_SCREENSHOTS, $editor->filter('.empty-state p')->text());
        self::assertCount(0, $editor->filter('button'));
    }

    #[Test]
    public function theTitleAndSummaryShowAsFieldsNotAsTextAboveThePicture(): void
    {
        $editor = $this->renderEditor(self::draftScreenshots());

        self::assertCount(0, $editor->filter('.screenshot__title'));
        self::assertCount(0, $editor->filter('.screenshot__summary'));
    }

    #[Test]
    public function untrustedTextIsEscapedAndNoStyleIsPrinted(): void
    {
        $view = self::view('draft-shot-1', '/media/shop-cart.png', [new PinView(1, 10.0, 10.0, "<script>alert(1)</script>\r\n日本語 \u{2028}end")], '"><b>x</b>', '<img src=x onerror=1>');

        $html = (string) $this->renderTwigComponent(ScreenshotEditor::class, ['screenshots' => [new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportDraft(0))], 'fieldId' => 'draft-shots']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('style="', $html);
        $crawler = new Crawler($html);
        self::assertSame('"><b>x</b>', $crawler->filter('input[name="screenshots[0][title]"]')->attr('value'));
        self::assertStringStartsWith('<script>alert(1)</script>', $crawler->filter('textarea.pin-list__note')->text());
    }

    #[Test]
    public function twoScreenshotsThatPlacePinsAreRefusedAsTheirPinFieldsWouldClash(): void
    {
        $first = new ScreenshotEditorItem(self::view('report-shot-1', '/media/shop-cart.png', []), ScreenshotFieldNames::ofReportForm());
        $second = new ScreenshotEditorItem(self::view('report-shot-2', '/media/shop-menu.png', []), ScreenshotFieldNames::ofReportForm());

        $reason = $this->refusalOf(['screenshots' => [$first, $second]]);

        self::assertSame('Only one screenshot of an editor may place pins: their fields are all named pins[n][…].', $reason);
        self::assertSame('', $this->refusalOf(['screenshots' => [$first]]), 'One pin-placing screenshot is the report form today.');
    }

    #[Test]
    public function aFieldIdThatIsNotALowercaseIdIsRefused(): void
    {
        self::assertSame('An editor id uses lowercase letters, digits and dashes, and starts with a letter.', $this->refusalOf(['screenshots' => [], 'fieldId' => 'Draft shots']));
        self::assertSame('', $this->refusalOf(['screenshots' => [], 'fieldId' => 'draft-shots-2']));
    }

    #[Test]
    public function aListThatIsNotOfEditorItemsIsRefused(): void
    {
        $view = self::view('draft-shot-1', '/media/shop-cart.png', []);

        self::assertSame('An editor shows ScreenshotEditorItem values in a list.', $this->refusalOf(['screenshots' => [$view]]));
        self::assertSame('An editor shows ScreenshotEditorItem values in a list.', $this->refusalOf(['screenshots' => [1 => self::draftScreenshots()[0]]]));
    }

    /**
     * Why mounting the editor with these props is refused, or '' when it renders.
     *
     * @param array<string, mixed> $props
     */
    private function refusalOf(array $props): string
    {
        try {
            $this->renderTwigComponent(ScreenshotEditor::class, $props);
        } catch (\Throwable $refused) {
            $reason = $refused instanceof \InvalidArgumentException ? $refused : $refused->getPrevious();
            self::assertInstanceOf(\InvalidArgumentException::class, $reason, 'Refused for another reason: '.$refused->getMessage());

            return $reason->getMessage();
        }

        return '';
    }

    /**
     * @param array<mixed> $screenshots
     */
    private function renderEditor(array $screenshots, string $fieldId = 'draft-shots', bool $canAdd = true): Crawler
    {
        $html = (string) $this->renderTwigComponent(ScreenshotEditor::class, ['screenshots' => $screenshots, 'fieldId' => $fieldId, 'canAdd' => $canAdd]);

        return new Crawler($html);
    }

    /**
     * Four screenshots of one draft, as the report draft page numbers them: the first two with pins, the third
     * without, the fourth with three pins whose first note is empty.
     *
     * @return list<ScreenshotEditorItem>
     */
    private static function draftScreenshots(): array
    {
        $views = [
            self::view('draft-shot-1', '/media/shop-cart.png', [new PinView(2, 60.0, 40.0, 'wrong currency'), new PinView(1, 20.0, 30.0, 'total counted twice')], 'Cart'),
            self::view('draft-shot-2', '/media/shop-checkout.png', [new PinView(1, 50.0, 90.0, 'button cut off')]),
            self::view('draft-shot-3', '/media/shop-receipt.png', []),
            self::view('draft-shot-4', '/media/shop-hours.png', [new PinView(1, 5.0, 5.0, ''), new PinView(2, 70.0, 10.0, 'date in the past'), new PinView(3, 30.0, 80.0, 'name missing')], 'Opening hours', 'The page says closed on a work day.'),
        ];

        return array_map(static fn (ScreenshotView $view, int $index): ScreenshotEditorItem => new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportDraft($index)), $views, array_keys($views));
    }

    /**
     * @param list<PinView> $pins
     */
    private static function view(string $domId, string $imageUrl, array $pins, ?string $title = null, ?string $summary = null): ScreenshotView
    {
        return new ScreenshotView($domId, $imageUrl, 800, 450, 'Screenshot of Acme Foods project SHOP', $title, $summary, $pins);
    }
}
