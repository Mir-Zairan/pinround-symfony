<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The checks on a short name that is shown in a list or on a page, as a token's name (ApiToken) or an OAuth client's
 * name (OAuthClient): Unicode space trimmed, at least one character that shows, and one line of plain text.
 */
final class OneLineText
{
    // Any Unicode space, the no-break and ideographic ones included, which trim() leaves in place.
    private const string SPACE_AT_EDGES = '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u';
    // A character that shows: a name of only spaces and zero-width or byte order marks reads as blank in a list.
    private const string VISIBLE_CHARACTER = '/[^\p{Z}\s\p{Cf}]/u';
    // C0/C1 controls, the Unicode line and paragraph separators, which would break a line in a log or a header, and
    // format characters (zero-width, bidirectional overrides), which would make a name look like another one.
    private const string LINE_BREAKING_CONTROL_OR_FORMAT = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /**
     * @return string|null $text without Unicode space at its edges; null when $text is not valid UTF-8
     */
    public static function trimmed(string $text): ?string
    {
        return mb_check_encoding($text, 'UTF-8') ? preg_replace(self::SPACE_AT_EDGES, '', $text) : null;
    }

    /**
     * Whether $text has no character that shows: only spaces and format characters, or nothing.
     */
    public static function isBlank(string $text): bool
    {
        return 1 !== preg_match(self::VISIBLE_CHARACTER, $text);
    }

    public static function hasControlLineBreakOrFormat(string $text): bool
    {
        return 1 === preg_match(self::LINE_BREAKING_CONTROL_OR_FORMAT, $text);
    }
}
