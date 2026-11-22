<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A screenshot: its title and summary as text above the picture, when it has them, then the picture with its pins
 * over it. Until the picture is ready a box of its shape, when known, says it is being processed or could not be.
 */
#[AsTwigComponent(name: 'Screenshot', template: '@Review/components/Screenshot.html.twig')]
final class Screenshot
{
    public ScreenshotView $view;
    public ?int $selectedNumber = null;

    /** The picture's loading attribute: "lazy" for a card among many, null for the browser's default (eager). */
    public ?string $loading = null;

    /** The level of the title's heading, 1 to 6; a screenshot inside a card whose heading is an h3 passes 4. */
    public int $headingLevel = 3;

    /**
     * False leaves the title and summary out, for a page that shows them elsewhere: the round page puts them in a row
     * of their own, so a Before and an After with texts of different lengths keep their pictures' tops in line.
     */
    public bool $showsText = true;

    public function titleTag(): string
    {
        return 'h'.max(1, min(6, $this->headingLevel));
    }

    public function frameId(): string
    {
        return $this->view->domId.'-frame';
    }

    public function placeholderId(): string
    {
        return $this->view->domId.'-placeholder';
    }
}
