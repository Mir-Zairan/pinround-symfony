<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A screenshot a person puts pins on: a form that holds the picture, an "Add pin" button, a status line for screen
 * readers, a note field per pin and the hidden x and y fields the pin-placer controller keeps in step. It posts the
 * fields pins[n][x], pins[n][y] and pins[n][note] to `action`.
 */
#[AsTwigComponent(name: 'EditableScreenshot', template: '@Review/components/EditableScreenshot.html.twig')]
final class EditableScreenshot
{
    public ScreenshotView $view;
    public string $action;
    public ?int $selectedNumber = null;

    public function formId(): string
    {
        return $this->view->domId.'-form';
    }

    public function noteMaxLength(): int
    {
        return PinView::NOTE_MAX_LENGTH;
    }
}
