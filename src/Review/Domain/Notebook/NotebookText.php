<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

/**
 * The text a notebook keeps. PostgreSQL refuses a NUL character in text and in jsonb, and json_encode refuses invalid
 * UTF-8, so neither may reach the rows.
 */
final class NotebookText
{
    /**
     * @throws NotebookRefused UNSAVABLE_TEXT when the text is not valid UTF-8 or holds a NUL character
     */
    public static function refuseUnlessSavable(?string $text): void
    {
        if (null !== $text && (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0"))) {
            throw NotebookRefused::because(NotebookRefused::UNSAVABLE_TEXT);
        }
    }
}
