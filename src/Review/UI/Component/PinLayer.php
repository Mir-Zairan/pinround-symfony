<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The pins of one screenshot as rings over the picture. Each sits at its x and y percent, so it stays on the same
 * spot when the window resizes.
 */
#[AsTwigComponent(name: 'PinLayer', template: '@Review/components/PinLayer.html.twig')]
final class PinLayer
{
    public ScreenshotView $view;
    public ?int $selectedNumber = null;

    public function layerId(): string
    {
        return $this->view->domId.'-pins';
    }
}
