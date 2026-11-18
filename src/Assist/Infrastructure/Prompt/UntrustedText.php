<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Prompt;

/**
 * The one cleaning of text that neither pinround nor the model controls: ticket data on its way into a prompt, and AI
 * answers on their way out. Both sides strip the same characters, so a marker split by an invisible character cannot
 * pass one side and look real on the other.
 */
final class UntrustedText
{
    /**
     * By Unicode category, not a list, so a new invisible character cannot slip through: control characters (C0 and C1)
     * but tab and newline, format characters (zero-width, bidi marks and isolates, invisible operators, soft hyphen, byte
     * order mark), private use, and the combining grapheme joiner U+034F, which is a mark, not a format character.
     */
    private const string INVISIBLE_CHARACTERS_PATTERN = '/(?![\t\n])[\p{Cc}\p{Cf}\p{Co}\x{034F}]/u';

    private function __construct()
    {
    }

    /**
     * Valid UTF-8 (invalid bytes become "?"), every line end as "\n" (CRLF, CR and the Unicode line and paragraph
     * separators), and none of the invisible characters above. Not trimmed, not cut, not escaped.
     *
     * @throws \RuntimeException when the text cannot be cleaned, so it is never passed on uncleaned or emptied
     */
    public static function normalise(string $text): string
    {
        $unixLines = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", mb_scrub($text, 'UTF-8'));

        return preg_replace(self::INVISIBLE_CHARACTERS_PATTERN, '', $unixLines)
            ?? throw new \RuntimeException('The text could not be cleaned.');
    }
}
