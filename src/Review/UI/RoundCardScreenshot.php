<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * A screenshot inside a round card. A draft's screenshots also carry their "Screenshot n" label and a read-only verdict;
 * the report's carry neither.
 */
final readonly class RoundCardScreenshot
{
    public const string NO_VERDICT = 'No verdict yet';
    public const string LOOKS_RIGHT = 'Looks right';
    public const string NOT_RIGHT = 'Not right yet';

    /**
     * @param string      $screenshotId the screenshot's id, which the draft editor's actions name
     * @param string|null $label        for example "Screenshot 2"; null when the card does not number its screenshots
     * @param string|null $verdictLabel one of the verdict constants; null when the card shows no verdict
     * @param string|null $verdictClass the tag's modifier class, so the verdict is also coloured
     */
    public function __construct(
        public string $screenshotId,
        public ScreenshotView $view,
        public ?string $label = null,
        public ?string $verdictLabel = null,
        public ?string $verdictClass = null,
    ) {
    }

    public function looksRight(): bool
    {
        return self::LOOKS_RIGHT === $this->verdictLabel;
    }

    public function isNotRightYet(): bool
    {
        return self::NOT_RIGHT === $this->verdictLabel;
    }
}
