<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A screenshot: its title and summary as text above the picture, when it has them, then the picture with its pins
 * over it.
 */
#[AsTwigComponent(name: 'Screenshot', template: '@Review/components/Screenshot.html.twig')]
final class Screenshot
{
    public ScreenshotView $view;
    public ?int $selectedNumber = null;
}
