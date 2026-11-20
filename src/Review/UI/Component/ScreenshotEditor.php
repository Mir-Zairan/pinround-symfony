<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotEditorItem;
use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The screenshots of a report, one at a time (P14.T10): the shown one large with its pins and their notes, its title
 * and summary beside it, "Remove screenshot", and a strip of thumbnails to switch, with "Add screenshot". It sits
 * inside the caller's form; every screenshot's fields post, shown or not, under the names of its ScreenshotFieldNames.
 *
 * The screenshot-editor controller switches screenshots and turns a remove field on and off. "Add screenshot" fires
 * screenshot-editor:add and "Remove screenshot" screenshot-editor:remove (detail.index) for the page to act on; a
 * screenshot without a remove field is removed only by the page. Renders the first screenshot shown.
 */
#[AsTwigComponent(name: 'ScreenshotEditor', template: '@Review/components/ScreenshotEditor.html.twig')]
final class ScreenshotEditor
{
    public const string EMPTY_MESSAGE = 'No screenshots yet. Add one to show where the problem is.';
    public const string NO_SCREENSHOTS = 'No screenshots.';

    /** @var array<mixed> a list of ScreenshotEditorItem, checked at mount */
    public array $screenshots = [];

    /** @var list<ScreenshotEditorItem> */
    private array $items = [];

    /** The editor's id, and the start of its tab and panel ids; lowercase letters, digits and dashes. */
    public string $fieldId = 'screenshots';

    /** Whether "Add screenshot" shows; the page that offers it listens for screenshot-editor:add. */
    public bool $canAdd = true;

    public string $notePlaceholder = ScreenshotReportField::NOTE_PLACEHOLDER;

    #[PostMount]
    public function refuseInvalidProps(): void
    {
        if (1 !== preg_match('/\A[a-z][a-z0-9-]*\z/', $this->fieldId)) {
            throw new \InvalidArgumentException('An editor id uses lowercase letters, digits and dashes, and starts with a letter.');
        }
        $items = [];
        foreach ($this->screenshots as $position => $item) {
            if ($position !== \count($items) || !$item instanceof ScreenshotEditorItem) {
                throw new \InvalidArgumentException('An editor shows ScreenshotEditorItem values in a list.');
            }
            $items[] = $item;
        }
        $pinPlacers = array_filter($items, static fn (ScreenshotEditorItem $item): bool => $item->fieldNames->placesPins());
        if (\count($pinPlacers) > 1) {
            throw new \InvalidArgumentException('Only one screenshot of an editor may place pins: their fields are all named pins[n][…].');
        }
        $this->items = $items;
    }

    /** @return list<ScreenshotEditorItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function tabId(int $index): string
    {
        return \sprintf('%s-tab-%d', $this->fieldId, $index + 1);
    }

    public function panelId(int $index): string
    {
        return \sprintf('%s-panel-%d', $this->fieldId, $index + 1);
    }

    public function titleMaxLength(): int
    {
        return ScreenshotView::TITLE_MAX_LENGTH;
    }

    public function summaryMaxLength(): int
    {
        return ScreenshotView::SUMMARY_MAX_LENGTH;
    }

    public function noteMaxLength(): int
    {
        return PinView::NOTE_MAX_LENGTH;
    }
}
