<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The text equivalent of the pin layer: each pin's number and note, read-only or as note fields.
 */
#[AsTwigComponent(name: 'PinList', template: '@Review/components/PinList.html.twig')]
final class PinList
{
    public ScreenshotView $view;
    public ?int $selectedNumber = null;
    public bool $isEditable = false;

    public function noteMaxLength(): int
    {
        return PinView::NOTE_MAX_LENGTH;
    }
}
