<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * One screenshot of a ScreenshotEditor: the screenshot with its title, summary and pins as the fields show them, the
 * names they post under, and whether it shows as removed (a refused form shown again after "Remove screenshot").
 *
 * A refused form shows again what was typed, which may be past the caps the view refuses: the typed title, summary
 * and pin notes, when given, are shown in the fields instead of the view's.
 */
final readonly class ScreenshotEditorItem
{
    /** The screenshot without its title and summary, for the picture: the editor shows those as fields beside it. */
    public ScreenshotView $picture;

    /** What the title field shows. */
    public string $title;

    /** What the summary field shows. */
    public string $summary;

    /**
     * @param array<int, string> $typedPinNotes by the pin's position among the view's pins, from 0
     */
    public function __construct(
        public ScreenshotView $view,
        public ScreenshotFieldNames $fieldNames,
        public bool $isRemoved = false,
        ?string $typedTitle = null,
        ?string $typedSummary = null,
        private array $typedPinNotes = [],
    ) {
        if ($isRemoved && null === $fieldNames->remove) {
            throw new \InvalidArgumentException('Only a screenshot with a remove field can show as removed.');
        }
        $this->picture = new ScreenshotView($view->domId, $view->imageUrl, $view->width, $view->height, $view->altText, pins: $view->pins, status: $view->status);
        $this->title = $typedTitle ?? $view->title ?? '';
        $this->summary = $typedSummary ?? $view->summary ?? '';
    }

    /** The note the field of the pin at this position (0 for the lowest number) shows: the typed one, or the pin's. */
    public function pinNoteAt(int $position): string
    {
        $pin = $this->view->pins[$position] ?? throw new \OutOfRangeException(\sprintf('The screenshot has no pin at position %d.', $position));

        return $this->typedPinNotes[$position] ?? $pin->note;
    }
}
