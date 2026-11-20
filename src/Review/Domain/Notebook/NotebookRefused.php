<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

/**
 * A notebook change refused by one of its rules, with the text to show the person. Nothing was changed.
 */
final class NotebookRefused extends \DomainException
{
    public const string TITLE_BLANK = 'Give the notebook a title.';
    public const string TITLE_TOO_LONG = 'Keep the notebook title to 120 characters or fewer.';
    public const string TOO_MANY_SCREENSHOTS = 'A notebook holds at most 10 screenshots.';
    public const string UNSAVABLE_TEXT = 'This text has a character we cannot save. Remove it and try again.';
    public const string SAME_PICTURE_TWICE = 'Each picture can be in the notebook only once.';
    public const string PICTURE_NOT_UPLOADED = 'Add a screenshot you uploaded yourself.';
    public const string PICTURE_TITLE_TOO_LONG = 'Keep the title to 120 characters or fewer.';
    public const string PICTURE_SUMMARY_TOO_LONG = 'Keep the summary to 600 characters or fewer.';
    public const string TOO_MANY_PINS = 'A picture holds at most 100 pins.';
    public const string PIN_OUTSIDE = 'A pin must sit inside the picture.';
    public const string PIN_NOTE_TOO_LONG = 'Keep the pin note to 1,000 characters or fewer.';

    /**
     * @param string $reason the text to show the person, one of this class's constants
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
