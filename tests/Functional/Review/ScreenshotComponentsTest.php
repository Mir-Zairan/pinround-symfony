<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Media\Api\MediaImageStatus;
use App\Review\UI\Component\EditableScreenshot;
use App\Review\UI\Component\PinLayer;
use App\Review\UI\Component\PinList;
use App\Review\UI\Component\Screenshot;
use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ScreenshotComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function titleAndSummaryShowAboveThePictureWhenPresent(): void
    {
        $crawler = $this->renderScreenshot(self::view(title: 'Checkout page', summary: 'The total is cut off.'));

        self::assertSame('Checkout page', $crawler->filter('.screenshot__title')->text());
        self::assertSame('The total is cut off.', $crawler->filter('.screenshot__summary')->text());
        $children = $crawler->filter('figure.screenshot')->children()->each(static fn (Crawler $child): string => (string) $child->attr('class'));
        self::assertSame(['screenshot__text', 'screenshot__frame'], $children);
        self::assertCount(1, $crawler->filter('.screenshot__frame img.screenshot__image[width="800"][height="450"][alt="Acme Foods checkout of project SHOP"]'));
        self::assertCount(1, $crawler->filter('.screenshot__frame .pin-layer'));
    }

    #[Test]
    public function aReadyScreenshotShowsItsPictureAndNoPlaceholder(): void
    {
        $html = $this->render(Screenshot::class, ['view' => self::view()]);
        $crawler = new Crawler($html);

        self::assertSame('/media/shop-checkout.png', $crawler->filter('.screenshot__frame img.screenshot__image')->attr('src'));
        self::assertCount(0, $crawler->filter('.screenshot__frame--shaped'));
        self::assertStringNotContainsString('<style', $html);
        self::assertCount(0, $crawler->filter('.screenshot__placeholder'));
        self::assertCount(0, $crawler->filter('[role="status"]'));
        self::assertCount(0, $crawler->filter('.screenshot__frame--placeholder'));
    }

    #[Test]
    public function aPendingScreenshotSaysItIsProcessingInABoxOfThePicturesShapeAndShowsNoPicture(): void
    {
        $html = $this->render(Screenshot::class, ['view' => self::pendingView(800, 450)]);
        $crawler = new Crawler($html);

        $placeholder = $crawler->filter('.screenshot__frame--placeholder #shot-2-placeholder.screenshot__placeholder');
        self::assertCount(1, $placeholder);
        self::assertSame('status', $placeholder->attr('role'));
        self::assertSame('Processing…', $placeholder->text());
        self::assertCount(0, $crawler->filter('img'));
        self::assertCount(0, $crawler->filter('.screenshot__placeholder--rejected'));
        self::assertCount(1, $crawler->filter('#shot-2-frame.screenshot__frame--shaped > [data-pin-placer-target="layer"]'));
        self::assertMatchesRegularExpression('/<style nonce="[A-Za-z0-9+\/=]+">#shot-2-frame\{max-width:800px;aspect-ratio:800 \/ 450\}<\/style>/', $html);
        self::assertStringNotContainsString('style="', $html);
        self::assertSame('Checkout page', $crawler->filter('.screenshot__title')->text());
    }

    #[Test]
    public function aPendingScreenshotOfUnknownSizePrintsNoShapeRule(): void
    {
        $html = $this->render(Screenshot::class, ['view' => self::pendingView(null, null)]);

        self::assertSame('Processing…', new Crawler($html)->filter('[role="status"]')->text());
        self::assertStringNotContainsString('<style', $html);
        self::assertCount(0, new Crawler($html)->filter('.screenshot__frame--shaped'));
    }

    #[Test]
    public function aRejectedScreenshotOfKnownSizeShapesItsFrameAndSaysItCouldNotBeProcessed(): void
    {
        $view = new ScreenshotView('shot-4', null, 800, 450, 'Acme Foods checkout of project SHOP', status: MediaImageStatus::Rejected);
        $html = $this->render(Screenshot::class, ['view' => $view]);
        $crawler = new Crawler($html);

        self::assertMatchesRegularExpression('/<style nonce="[A-Za-z0-9+\/=]+">#shot-4-frame\{max-width:800px;aspect-ratio:800 \/ 450\}<\/style>/', $html);
        self::assertCount(1, $crawler->filter('#shot-4-frame.screenshot__frame--shaped #shot-4-placeholder.screenshot__placeholder--rejected'));
        self::assertSame('We could not process this image. Try again.', $crawler->filter('#shot-4-placeholder')->text());
        self::assertStringNotContainsString('style="', $html);
    }

    #[Test]
    public function aRejectedScreenshotSaysItCouldNotBeProcessedAndShowsNoPicture(): void
    {
        $view = new ScreenshotView('shot-3', null, null, null, 'Acme Foods checkout of project SHOP', status: MediaImageStatus::Rejected);
        $crawler = $this->renderScreenshot($view);

        $placeholder = $crawler->filter('#shot-3-placeholder.screenshot__placeholder.screenshot__placeholder--rejected');
        self::assertCount(1, $placeholder);
        self::assertSame('We could not process this image. Try again.', $placeholder->text());
        self::assertCount(0, $crawler->filter('img'));
        self::assertCount(0, $crawler->filter('[role="status"]'));
        self::assertStringNotContainsString('Processing', $crawler->html());
    }

    #[Test]
    public function aPendingScreenshotKeepsItsPinLayerAndItsPinsListed(): void
    {
        $view = new ScreenshotView('shot-2', null, 800, 450, 'Acme Foods checkout of project SHOP', pins: self::pins(), status: MediaImageStatus::Pending);

        $screenshot = $this->renderScreenshot($view);
        $list = new Crawler($this->render(PinList::class, ['view' => $view]));

        self::assertCount(1, $screenshot->filter('.screenshot__frame [data-pin-placer-target="layer"]'));
        self::assertSame(['1', '2', '4'], $list->filter('.pin-list__number')->each(static fn (Crawler $number): string => $number->text()));
    }

    #[Test]
    public function onlyTheTitleShowsWhenThereIsNoSummary(): void
    {
        $crawler = $this->renderScreenshot(self::view(title: 'Checkout page'));

        self::assertCount(1, $crawler->filter('.screenshot__title'));
        self::assertCount(0, $crawler->filter('.screenshot__summary'));
    }

    #[Test]
    public function theTextBoxIsAbsentWhenTitleAndSummaryAreEmpty(): void
    {
        $crawler = $this->renderScreenshot(self::view(title: '  ', summary: ''));

        self::assertCount(0, $crawler->filter('.screenshot__text'));
        self::assertCount(0, $crawler->filter('h3'));
        self::assertCount(1, $crawler->filter('.screenshot__image'));
    }

    #[Test]
    public function everyMarkerSitsAtItsPercentAndCarriesItsAccessibleName(): void
    {
        $html = $this->render(PinLayer::class, ['view' => self::view(pins: self::pins())]);
        $crawler = new Crawler($html);

        self::assertSame(
            ['Pin 1: address is cut off', 'Pin 2: header is cut off', 'Pin 4'],
            $crawler->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')),
        );
        self::assertStringContainsString('#shot-1-pins .pin--n2{left:70.50%;top:12.25%}', $html);
        self::assertStringContainsString('#shot-1-pins .pin--n1{left:0.00%;top:100.00%}', $html);
        self::assertStringNotContainsString('style="', $html);
        self::assertSame('70.50', $crawler->filter('.pin--n2')->attr('data-x-percent'));
        self::assertMatchesRegularExpression('/<style nonce="[A-Za-z0-9+\/=]+">/', $html);
    }

    #[Test]
    public function aLayerWithoutPinsPrintsNoStyleElement(): void
    {
        $html = $this->render(PinLayer::class, ['view' => self::view()]);

        self::assertStringNotContainsString('<style', $html);
        self::assertCount(0, new Crawler($html)->filter('button.pin'));
    }

    #[Test]
    public function theNoteBubbleHoldsTheNoteAndAnEmptyNoteHasNoBubble(): void
    {
        $crawler = new Crawler($this->render(PinLayer::class, ['view' => self::view(pins: self::pins())]));

        self::assertSame('header is cut off', $crawler->filter('.pin--n2 .pin__bubble')->text());
        self::assertSame('2', $crawler->filter('.pin--n2 .pin__tab')->text());
        self::assertCount(0, $crawler->filter('.pin--n4 .pin__bubble'));
        self::assertCount(1, $crawler->filter('.pin--n2.pin--bubble-left'));
        self::assertCount(0, $crawler->filter('.pin--n1.pin--bubble-left'));
    }

    #[Test]
    public function onlyTheSelectedPinIsMarkedSelectedAndPressed(): void
    {
        $crawler = new Crawler($this->render(PinLayer::class, ['view' => self::view(pins: self::pins()), 'selectedNumber' => 2]));

        self::assertCount(1, $crawler->filter('.pin--selected'));
        self::assertSame('true', $crawler->filter('.pin--n2')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('.pin--n1')->attr('aria-pressed'));
    }

    #[Test]
    public function aiPinsCarryTheAiClassAndPlainPinsDoNot(): void
    {
        $crawler = new Crawler($this->render(PinLayer::class, ['view' => self::view(pins: self::pins())]));

        self::assertSame(['4'], $crawler->filter('.pin--ai')->each(static fn (Crawler $pin): string => (string) $pin->attr('data-pin-number')));
    }

    #[Test]
    public function theListShowsTheSameNumbersAndNotesAsTheLayer(): void
    {
        $view = self::view(pins: self::pins());
        $layer = new Crawler($this->render(PinLayer::class, ['view' => $view]));
        $list = new Crawler($this->render(PinList::class, ['view' => $view]));

        $numbers = $list->filter('.pin-list__number')->each(static fn (Crawler $number): string => $number->text());
        self::assertSame(['1', '2', '4'], $numbers);
        self::assertSame($numbers, $layer->filter('.pin__tab')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(['address is cut off', 'header is cut off', ''], $list->filter('p.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertCount(0, $list->filter('textarea'));
        self::assertCount(1, $list->filter('.pin-list__item--ai'));
    }

    #[Test]
    public function theEditableListHoldsOneNoteFieldPerPinWithoutAMaxlengthAttribute(): void
    {
        $list = new Crawler($this->render(PinList::class, ['view' => self::view(pins: self::pins()), 'isEditable' => true]));

        $fields = $list->filter('textarea.pin-list__note');
        self::assertCount(3, $fields);
        self::assertSame('pins[2][note]', $fields->eq(1)->attr('name'));
        self::assertSame('Note for pin 2', $fields->eq(1)->attr('aria-label'));
        self::assertNull($fields->eq(1)->attr('maxlength'), 'maxlength counts UTF-16 units; the controller enforces the code-point limit');
        self::assertSame('header is cut off', $fields->eq(1)->text());
        $removeButtons = $list->filter('button.pin-list__remove[data-pin-remove]');
        self::assertCount(3, $removeButtons);
        self::assertSame('Remove pin 2', $removeButtons->eq(1)->attr('aria-label'));
        self::assertSame('button', $removeButtons->eq(1)->attr('type'));
        self::assertCount(1, $list->filter('li[data-pin-number="2"] button[data-pin-remove]'));
    }

    #[Test]
    public function theReadOnlyListHasNoNoteFieldsAndNoRemoveButtons(): void
    {
        $list = new Crawler($this->render(PinList::class, ['view' => self::view(pins: self::pins())]));

        self::assertCount(0, $list->filter('[data-pin-remove]'));
        self::assertCount(0, $list->filter('textarea'));
    }

    #[Test]
    public function onlyTheSelectedPinsListItemIsMarkedSelected(): void
    {
        $list = new Crawler($this->render(PinList::class, ['view' => self::view(pins: self::pins()), 'selectedNumber' => 2]));

        $selected = $list->filter('.pin-list__item--selected');
        self::assertCount(1, $selected);
        self::assertSame('2', $selected->attr('data-pin-number'));
        self::assertCount(3, $list->filter('.pin-list__item'));
    }

    #[Test]
    public function theEditableScreenshotIsAFormWithTheControllerItsButtonStatusFieldsAndPinsOnTheServer(): void
    {
        $html = $this->render(EditableScreenshot::class, ['view' => self::view(pins: self::pins()), 'action' => '/pins/save']);
        $form = new Crawler($html)->filter('form.pin-placer');

        self::assertCount(1, $form);
        self::assertSame('shot-1-form', $form->attr('id'));
        self::assertSame('post', $form->attr('method'));
        self::assertSame('/pins/save', $form->attr('action'));
        self::assertSame('pin-placer', $form->attr('data-controller'));
        self::assertSame('1000', $form->attr('data-pin-placer-note-max-value'));
        self::assertStringContainsString('click->pin-placer#onClick', (string) $form->attr('data-action'));
        self::assertStringContainsString('pointercancel->pin-placer#onPointerend', (string) $form->attr('data-action'));
        self::assertCount(1, $form->filter('figure#shot-1 [data-pin-placer-target="layer"]'));
        self::assertCount(3, $form->filter('button.pin'));
        self::assertCount(1, $form->filter('ol.pin-list[data-pin-placer-target="list"]'));
        self::assertCount(3, $form->filter('textarea.pin-list__note'));
        self::assertCount(3, $form->filter('[data-pin-remove]'));
        self::assertSame('Add pin', $form->filter('button[data-pin-add]')->text());
        self::assertSame('pin-placer#addAtCentre', $form->filter('button[data-pin-add]')->attr('data-action'));
        self::assertCount(1, $form->filter('p[role="status"][data-pin-placer-target="status"]'));
        self::assertCount(1, $form->filter('div[hidden][data-pin-placer-target="fields"]'));
    }

    #[Test]
    public function theEditableScreenshotWithoutPinsStillHasTheAddButtonAndAnEmptyList(): void
    {
        $form = new Crawler($this->render(EditableScreenshot::class, ['view' => self::view(), 'action' => '#']))->filter('form.pin-placer');

        self::assertCount(0, $form->filter('button.pin'));
        self::assertCount(1, $form->filter('ol.pin-list'));
        self::assertCount(0, $form->filter('li'));
        self::assertCount(1, $form->filter('button[data-pin-add]'));
    }

    #[Test]
    public function aListWithoutPinsPrintsNothing(): void
    {
        self::assertSame('', trim($this->render(PinList::class, ['view' => self::view()])));
    }

    #[Test]
    public function untrustedNoteTextIsEscapedAndNeverBreaksTheMarkup(): void
    {
        $note = "<script>alert(1)</script>\r\n\"><b>x</b> 日本語 \u{2028} \u{feff}end";
        $view = self::view(title: '<script>t</script>', summary: '<img src=x onerror=1>', pins: [new PinView(1, 10.0, 10.0, $note)]);

        $page = $this->render(Screenshot::class, ['view' => $view]).$this->render(PinList::class, ['view' => $view, 'isEditable' => true]);

        self::assertStringNotContainsString('<script>', $page);
        self::assertStringNotContainsString('<b>x</b>', $page);
        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $crawler = new Crawler($page);
        $label = (string) $crawler->filter('button.pin')->attr('aria-label');
        self::assertStringStartsWith('Pin 1: <script>alert(1)</script> "><b>x</b> 日本語', $label);
        self::assertDoesNotMatchRegularExpression('/[\r\n\x{2028}]/u', $label);
        self::assertStringStartsWith('<script>alert(1)</script>', $crawler->filter('.pin__bubble')->text());
    }

    #[Test]
    public function aNoteWithAnEscapeCharacterDoesNotReachTheAccessibleName(): void
    {
        $view = self::view(pins: [new PinView(1, 10.0, 10.0, "red\u{1b}[31m")]);

        $label = (string) new Crawler($this->render(PinLayer::class, ['view' => $view]))->filter('button.pin')->attr('aria-label');

        self::assertSame('Pin 1: red [31m', $label);
    }

    /**
     * @param class-string         $component
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props): string
    {
        return (string) $this->renderTwigComponent($component, $props);
    }

    private function renderScreenshot(ScreenshotView $view): Crawler
    {
        return new Crawler($this->render(Screenshot::class, ['view' => $view]));
    }

    /**
     * @param list<PinView> $pins
     */
    private static function view(?string $title = null, ?string $summary = null, array $pins = []): ScreenshotView
    {
        return new ScreenshotView('shot-1', '/media/shop-checkout.png', 800, 450, 'Acme Foods checkout of project SHOP', $title, $summary, $pins);
    }

    private static function pendingView(?int $width, ?int $height): ScreenshotView
    {
        return new ScreenshotView('shot-2', null, $width, $height, 'Acme Foods checkout of project SHOP', 'Checkout page', status: MediaImageStatus::Pending);
    }

    /**
     * Out of order on purpose; number 3 was deleted.
     *
     * @return list<PinView>
     */
    private static function pins(): array
    {
        return [
            new PinView(4, 40.0, 40.0, '', isAiSuggested: true),
            new PinView(2, 70.5, 12.25, 'header is cut off'),
            new PinView(1, 0.0, 100.0, 'address is cut off'),
        ];
    }
}
