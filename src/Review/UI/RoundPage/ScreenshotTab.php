<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * One tab in the round page's tab row: a link to that screenshot, with its pin count or, for a screen area, the
 * area's state.
 */
final readonly class ScreenshotTab
{
    /**
     * @param int                $number     from 1, the screenshot's place in the row
     * @param string             $label      the screen area's name, the picture's title, or "Screenshot 2" without one
     * @param array<string, int> $parameters the round page's query that shows this screenshot, for example ["screenshot" => 2]
     * @param string             $tagLabel   "2 pins", or the area's state, for example "Passed" or "3 pins"
     * @param string             $tagClass   the tag's modifier class, so a state is also coloured
     * @param string|null        $tagId      the tag's element id, which a verdict's stream replaces; null for a
     *                                       screenshot of a round without areas, which takes no verdict here
     */
    public function __construct(
        public int $number,
        public string $label,
        public array $parameters,
        public string $tagLabel,
        public string $tagClass,
        public ?string $tagId,
        public bool $isShown,
    ) {
    }

    /** The tab link's element id. */
    public function elementId(): string
    {
        return 'round-tab-'.$this->number;
    }
}
