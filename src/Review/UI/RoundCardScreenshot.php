<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Review\UI\RoundPage\EarlierPinRow;

/**
 * A screenshot inside a round card. A draft's screenshots also carry their "Screenshot n" label and a read-only verdict;
 * the report's carry neither. In the owner's draft a screenshot is the latest try of its chain (ADR-0048) and carries
 * its earlier tries, each with its pins as rows that can be marked "Fixed on try N" or "Still wrong".
 */
final readonly class RoundCardScreenshot
{
    public const string NO_VERDICT = 'No verdict yet';
    public const string LOOKS_RIGHT = 'Looks right';
    public const string NOT_RIGHT = 'Not right yet';

    public const string TRY_OF = 'Try %d of %d';
    /** %d is the chain's latest try, the one that fixed the pin. */
    public const string PIN_FIXED_ON_TRY = 'Fixed on try %d';
    public const string PIN_STILL_WRONG = 'Still wrong';
    public const string PIN_NOT_CHECKED = 'Not checked yet';

    /**
     * @param string                    $screenshotId the screenshot's id, which the draft editor's actions name
     * @param string|null               $label        for example "Screenshot 2"; null when the card does not number its screenshots
     * @param string|null               $verdictLabel one of the verdict constants; null when the card shows no verdict
     * @param string|null               $verdictClass the tag's modifier class, so the verdict is also coloured
     * @param int                       $tryNumber    from 1, its place in its chain of tries
     * @param int                       $tryCount     the tries in its chain, from $tryNumber
     * @param list<RoundCardScreenshot> $earlierTries by try, for the latest try of a chain; empty for any other
     * @param list<EarlierPinRow>       $pinRows      the pins of an earlier try with their mark; empty for the latest try
     */
    public function __construct(
        public string $screenshotId,
        public ScreenshotView $view,
        public ?string $label = null,
        public ?string $verdictLabel = null,
        public ?string $verdictClass = null,
        public int $tryNumber = 1,
        public int $tryCount = 1,
        public array $earlierTries = [],
        public array $pinRows = [],
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

    /**
     * "Try 2 of 2"; null for a screenshot no one retook.
     */
    public function tryLabel(): ?string
    {
        return $this->tryCount > 1 ? \sprintf(self::TRY_OF, $this->tryNumber, $this->tryCount) : null;
    }

    public function isLatestTry(): bool
    {
        return $this->tryNumber === $this->tryCount;
    }

    /**
     * @return list<RoundCardScreenshot> every try of the chain by try, this latest one last
     */
    public function tries(): array
    {
        return [...$this->earlierTries, $this];
    }

    /**
     * The mark a pin of an earlier try gets when the latest try fixed it.
     */
    public function pinFixedLabel(): string
    {
        return \sprintf(self::PIN_FIXED_ON_TRY, $this->tryCount);
    }

    public function isPinFixed(EarlierPinRow $pin): bool
    {
        return $this->pinFixedLabel() === $pin->verdictLabel;
    }

    public function isPinStillWrong(EarlierPinRow $pin): bool
    {
        return self::PIN_STILL_WRONG === $pin->verdictLabel;
    }
}
