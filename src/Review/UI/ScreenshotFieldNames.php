<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * The names a screenshot's fields post under in the ScreenshotEditor, as the form that reads them expects.
 *
 * Report a problem posts one screenshot as screenshot_title and screenshot_summary; its pins are placed by the
 * pin-placer controller, which names them pins[n][x], pins[n][y] and pins[n][note]. A report draft posts each of its
 * screenshots under screenshots[i]: title, summary, remove ("1" to drop it) and each pin's note under pins[p], where p
 * is the pin's position among the screenshot's pins; a draft's pins keep their spots.
 */
final readonly class ScreenshotFieldNames
{
    private function __construct(
        public string $title,
        public string $summary,
        public ?string $remove,
        private ?string $pinNotesName,
    ) {
    }

    public static function ofReportForm(): self
    {
        return new self('screenshot_title', 'screenshot_summary', null, null);
    }

    public static function ofReportDraft(int $index): self
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('A draft screenshot index starts at 0.');
        }
        $prefix = \sprintf('screenshots[%d]', $index);

        return new self($prefix.'[title]', $prefix.'[summary]', $prefix.'[remove]', $prefix.'[pins]');
    }

    /** Whether people place, move and remove the pins, through the pin-placer controller and its field names. */
    public function placesPins(): bool
    {
        return null === $this->pinNotesName;
    }

    /** The name of the note field of the pin at this position (0 for the lowest number) of a screenshot whose pins stay put. */
    public function pinNoteNameOf(int $position): string
    {
        if (null === $this->pinNotesName) {
            throw new \LogicException('The pin-placer names these pins pins[n][…].');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('A pin position starts at 0.');
        }

        return \sprintf('%s[%d]', $this->pinNotesName, $position);
    }
}
