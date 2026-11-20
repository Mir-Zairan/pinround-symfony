<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A notebook command refused, with the text to show the person. Nothing was saved. A notebook that is not the person's,
 * is another organization's, or does not exist is NOT_FOUND alike, so a notebook's existence leaks to nobody but its
 * owner. Besides this class's texts, the reason can be one of the notebook's rules ("A notebook holds at most 10
 * screenshots.") or of the scenario format ("Scenario 2, step 5: …"), which never repeats a value the caller sent.
 */
final class NotebookRefused extends \DomainException
{
    public const string NOT_FOUND = 'That notebook does not exist.';
    public const string NOTEBOOK_CHANGED = 'This notebook changed in another tab. Reload and try again.';
    public const string NOT_YOURS_TO_MAKE = 'You can make a notebook only for yourself, in your own organization.';

    private bool $isNotFound = false;

    public static function notFound(): self
    {
        $refused = new self(self::NOT_FOUND);
        $refused->isNotFound = true;

        return $refused;
    }

    /**
     * @param string $reason the text to show the person: one of this class's constants, a notebook or scenario rule's,
     *                       or a RoundRefused MEDIA_ text
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
