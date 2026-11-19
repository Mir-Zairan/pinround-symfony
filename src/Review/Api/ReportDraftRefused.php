<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A report draft command refused, with the text to show the person. Nothing was saved. A draft that is not the
 * person's, or does not exist, is NOT_FOUND alike, so a draft's existence leaks to nobody but its owner.
 */
final class ReportDraftRefused extends \DomainException
{
    public const string NOT_FOUND = 'That report draft does not exist.';
    public const string PROJECT_NOT_FOUND = 'There is no project with that key.';
    public const string PROJECT_HAS_NO_TEAM = 'No team owns this project yet. Pick another project.';
    public const string TITLE_BLANK = 'Write what is wrong in one line.';
    public const string TITLE_TOO_LONG = 'Keep the title to 200 characters or fewer.';
    public const string DETAIL_TOO_LONG = 'Keep the detail to 50,000 characters or fewer.';
    public const string TOO_MANY_SCREENSHOTS = 'A report holds at most 10 screenshots.';
    public const string PICTURE_TITLE_TOO_LONG = 'Keep the title to 120 characters or fewer.';
    public const string PICTURE_SUMMARY_TOO_LONG = 'Keep the summary to 600 characters or fewer.';
    public const string PIN_NOTE_TOO_LONG = 'Keep the pin note to 1,000 characters or fewer.';
    public const string PIN_OUTSIDE = 'A pin must sit inside the picture.';
    public const string TOO_MANY_PINS = 'A picture holds at most 100 pins.';
    public const string SAME_PICTURE_TWICE = 'Each picture can be in the report only once.';
    public const string DRAFT_CHANGED = 'This report draft changed in another tab. Reload and try again.';

    private bool $isNotFound = false;

    public static function notFound(): self
    {
        $refused = new self(self::NOT_FOUND);
        $refused->isNotFound = true;

        return $refused;
    }

    /**
     * @param string $reason the text to show the person, one of this class's constants or a RoundRefused MEDIA_ text
     */
    public static function because(string $reason, ?\Throwable $cause = null): self
    {
        return new self($reason, 0, $cause);
    }

    public function isNotFound(): bool
    {
        return $this->isNotFound;
    }
}
