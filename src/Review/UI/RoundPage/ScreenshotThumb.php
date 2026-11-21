<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * One small picture in the round page's screenshot strip: a link to that screenshot, with its pin count or, for a
 * screen area, the area's state.
 */
final readonly class ScreenshotThumb
{
    /**
     * @param int                $number     from 1, the screenshot's place in the strip and in "2 of 3"
     * @param string             $label      "Screenshot 2", or the screen area's name
     * @param array<string, int> $parameters the round page's query that shows this screenshot, for example ["screenshot" => 2]
     * @param string|null        $imageUrl   the signed small picture; null while it is not ready or could not be read
     * @param string             $tagLabel   "2 pins", or the area's state, for example "Passed" or "3 pins"
     * @param string             $tagClass   the tag's modifier class, so a state is also coloured
     * @param string|null        $tagId      the tag's element id, which a verdict's stream replaces; null for a
     *                                       screenshot of a round without areas, which takes no verdict here
     */
    public function __construct(
        public int $number,
        public string $label,
        public array $parameters,
        public ?string $imageUrl,
        public string $tagLabel,
        public string $tagClass,
        public ?string $tagId,
        public bool $isShown,
    ) {
    }
}
