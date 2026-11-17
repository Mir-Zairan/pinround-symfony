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
     * Control characters but tab and newline, zero-width characters (U+200B to U+200D, U+2060), the soft hyphen and byte
     * order marks.
     */
    private const string INVISIBLE_CHARACTERS_PATTERN = '/[\x00-\x08\x0B-\x1F\x7F\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

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
