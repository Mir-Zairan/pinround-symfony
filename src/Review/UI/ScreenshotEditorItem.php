<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * One screenshot of a ScreenshotEditor: the screenshot with its title, summary and pins as the fields show them, the
 * names they post under, and whether it shows as removed (a refused form shown again after "Remove screenshot").
 */
final readonly class ScreenshotEditorItem
{
    /** The screenshot without its title and summary, for the picture: the editor shows those as fields beside it. */
    public ScreenshotView $picture;

    public function __construct(
        public ScreenshotView $view,
        public ScreenshotFieldNames $fieldNames,
        public bool $isRemoved = false,
    ) {
        if ($isRemoved && null === $fieldNames->remove) {
            throw new \InvalidArgumentException('Only a screenshot with a remove field can show as removed.');
        }
        $this->picture = new ScreenshotView($view->domId, $view->imageUrl, $view->width, $view->height, $view->altText, pins: $view->pins, status: $view->status);
    }
}
