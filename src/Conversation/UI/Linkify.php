<?php

declare(strict_types=1);

namespace App\Conversation\UI;

use Twig\Attribute\AsTwigFilter;

/**
 * Turns a message's plain text into HTML: every character escaped, http and https addresses as links, line breaks as
 * <br>. No other scheme is linked, so javascript: and data: stay text.
 */
final class Linkify
{
    // Not after a letter, digit or slash, so "xhttps://" and "javascript://" do not start a link midway.
    private const string ADDRESS = '~(?<![\p{L}\p{N}/])https?://[^\s<>"]+~iu';
    // Punctuation that usually ends the sentence around an address rather than the address itself.
    private const string TRAILING_PUNCTUATION = '.,;:!?\'")]}';
    private const array BRACKETS = ['(' => ')', '[' => ']', '{' => '}'];

    #[AsTwigFilter('linkify', isSafe: ['html'])]
    public function linkify(?string $text): string
    {
        if (null === $text || '' === $text) {
            return '';
        }

        if (false === preg_match_all(self::ADDRESS, $text, $addresses, \PREG_OFFSET_CAPTURE)) {
            // Not valid UTF-8: escaping substitutes the bad bytes and nothing is linked.
            return self::withLineBreaks(self::escape($text));
        }

        $html = '';
        $previousEnd = 0;
        foreach ($addresses[0] as [$address, $offset]) {
            $html .= self::escape(substr($text, $previousEnd, $offset - $previousEnd));
            [$linked, $trailing] = self::splitTrailingPunctuation($address);
            $html .= self::isLinkable($linked)
                ? \sprintf('<a href="%1$s" rel="noopener noreferrer" target="_blank">%1$s</a>', self::escape($linked))
                : self::escape($linked);
            $html .= self::escape($trailing);
            $previousEnd = $offset + \strlen($address);
        }
        $html .= self::escape(substr($text, $previousEnd));

        return self::withLineBreaks($html);
    }

    /**
     * @return array{string, string} the address, then the punctuation after it; a closing bracket stays when the
     *                               address opens one, as in a wiki address
     */
    private static function splitTrailingPunctuation(string $address): array
    {
        $end = \strlen($address);
        while ($end > 0 && str_contains(self::TRAILING_PUNCTUATION, $address[$end - 1])) {
            $closing = $address[$end - 1];
            $opener = array_search($closing, self::BRACKETS, true);
            if (false !== $opener && substr_count($address, $opener, 0, $end) >= substr_count($address, $closing, 0, $end)) {
                break;
            }
            --$end;
        }

        return [substr($address, 0, $end), substr($address, $end)];
    }

    private static function isLinkable(string $address): bool
    {
        return 1 === preg_match('~^https?://[^\s/?#]~i', $address);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    private static function withLineBreaks(string $html): string
    {
        return strtr($html, ["\r\n" => "<br>\n", "\r" => "<br>\n", "\n" => "<br>\n"]);
    }
}
