<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotEditorItem;
use App\Review\UI\ScreenshotFieldNames;
use App\Review\UI\ScreenshotView;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The component gallery's screenshot editor: three screenshots of a made-up Acme Foods report draft of project SHOP,
 * the first with a title, a summary and two pins, the second with one pin, the third bare. Each posts under the report
 * draft's field names, so each has a remove field. Built here for the same reason as ScreenshotExample.
 */
#[WhenNot('prod')]
#[AsTwigComponent(name: 'ScreenshotEditorExample', template: '@Review/components/ScreenshotEditorExample.html.twig')]
final class ScreenshotEditorExample
{
    /** @return list<ScreenshotEditorItem> */
    public function screenshots(): array
    {
        $views = [
            self::view(1, 'Checkout page', 'The order total is cut off on small screens.', [
                new PinView(1, 28.0, 30.0, 'The address field is cut off.'),
                new PinView(2, 70.0, 45.5, 'The total is hidden behind the pay button.'),
            ]),
            self::view(2, 'Cart', null, [new PinView(1, 45.0, 62.0, 'The quantity does not update.')]),
            self::view(3, null, null, []),
        ];

        return array_map(
            static fn (ScreenshotView $view, int $index): ScreenshotEditorItem => new ScreenshotEditorItem($view, ScreenshotFieldNames::ofReportDraft($index)),
            $views,
            array_keys($views),
        );
    }

    /**
     * @param list<PinView> $pins
     */
    private static function view(int $number, ?string $title, ?string $summary, array $pins): ScreenshotView
    {
        return new ScreenshotView(
            'gallery-editor-shot-'.$number,
            'data:image/svg+xml;base64,'.base64_encode(ScreenshotExample::PICTURE),
            800,
            450,
            \sprintf('Screenshot %d of an Acme Foods report on project SHOP', $number),
            $title,
            $summary,
            $pins,
        );
    }
}
