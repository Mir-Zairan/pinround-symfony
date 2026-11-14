<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The component gallery's screenshot: a made-up Acme Foods checkout page with a title, a summary and three pins (a
 * plain one, a selected one and one an AI suggested). The gallery lives in Web, which may not build Review's view
 * models, so this one builds them.
 */
#[WhenNot('prod')]
#[AsTwigComponent(name: 'ScreenshotExample', template: '@Review/components/ScreenshotExample.html.twig')]
final class ScreenshotExample
{
    private const string PICTURE = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">'
        .'<rect width="800" height="450" fill="#e9efec"/><rect width="800" height="64" fill="#d3dcd8"/>'
        .'<rect x="40" y="110" width="440" height="40" rx="8" fill="#ffffff"/>'
        .'<rect x="40" y="180" width="440" height="40" rx="8" fill="#ffffff"/>'
        .'<rect x="560" y="110" width="200" height="260" rx="12" fill="#ffffff"/></svg>';

    /** An editable example is the form of the pin-placer controller; it starts empty unless `hasPins`. */
    public bool $isEditable = false;

    /** Pins 1, 2 and 4 come with the page (a deleted pin 3 leaves a gap), as they will from the database. */
    public bool $hasPins = false;

    public function view(): ScreenshotView
    {
        if ($this->isEditable) {
            return new ScreenshotView(
                $this->hasPins ? 'gallery-placed-screenshot' : 'gallery-editable-screenshot',
                'data:image/svg+xml;base64,'.base64_encode(self::PICTURE),
                800,
                450,
                'The Acme Foods checkout page of project SHOP, open for pins',
                pins: $this->hasPins ? [
                    new PinView(1, 28.0, 30.0, 'The address field is cut off.'),
                    new PinView(2, 70.55, 45.5, 'The total is hidden behind the pay button.'),
                    new PinView(4, 45.0, 62.25, ''),
                ] : [],
            );
        }

        return new ScreenshotView(
            'gallery-screenshot',
            'data:image/svg+xml;base64,'.base64_encode(self::PICTURE),
            800,
            450,
            'The Acme Foods checkout page of project SHOP',
            'Checkout page',
            'The order total is cut off on small screens and the pay button sits too close to it.',
            [
                new PinView(1, 28.0, 30.0, 'The address field is cut off.'),
                new PinView(2, 70.0, 45.5, 'The total is hidden behind the pay button.'),
                new PinView(3, 45.0, 62.0, 'This label may be too long.', isAiSuggested: true),
            ],
        );
    }

    public function selectedNumber(): ?int
    {
        return $this->isEditable ? null : 2;
    }
}
