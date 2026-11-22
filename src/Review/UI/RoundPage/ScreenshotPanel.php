<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * The side panel of a screenshot of a round without screen areas: the pins of its Before, when it has one, and its
 * own, each with its check item's state and buttons when it has one, and, on a shared draft, the verdict on it.
 */
final readonly class ScreenshotPanel
{
    /**
     * @param string|null                   $earlierPinsHeading for example "Pins from round 0"; null when the screenshot has no Before
     * @param list<PicturePinRow>           $earlierPins        the Before's pins, by number
     * @param string                        $ownPinsHeading     for example "Pins from round 2"
     * @param list<PicturePinRow>           $ownPins            the screenshot's own pins, by number
     * @param ScreenshotVerdictSection|null $verdict            a shared draft's verdict on the screenshot; null on any other round
     */
    public function __construct(
        public ?string $earlierPinsHeading,
        public array $earlierPins,
        public string $ownPinsHeading,
        public array $ownPins,
        public ?ScreenshotVerdictSection $verdict,
    ) {
    }
}
