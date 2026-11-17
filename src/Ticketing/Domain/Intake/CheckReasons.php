<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * The check reasons of a draft report (FR-INT-08, FR-INT-09). P06.T3 adds the reasons themselves; for now it answers
 * whether a draft is security sensitive, which keeps it team-only and away from cloud AI.
 */
final readonly class CheckReasons
{
    /**
     * Format characters and every default-ignorable code point (zero-width space, joiners, soft hyphen, BOM, variation
     * selectors, the combining grapheme joiner, Hangul fillers) are removed, so an invisible character cannot split a
     * word past the list.
     */
    private const string INVISIBLE_CHARACTERS = '/[\p{Cf}\p{DI}]+/u';

    /**
     * True when the security word list matches the title, the detail or any pin note. Each text is NFKC-normalized
     * first, so full-width and other compatibility letters read as the plain ones. A text that is not valid UTF-8
     * cannot be checked, so it counts as sensitive: the check fails closed.
     */
    public function isSecuritySensitive(DraftReport $draft): bool
    {
        // A letter or digit on either side ends a match, so "hackö", "token2" and "untoken" are not list words.
        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', WordLists::SECURITY).')(?![\p{L}\p{N}])/iu';
        foreach ([$draft->title, $draft->detail, ...$draft->pinNotes] as $text) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (!\is_string($normalized)) {
                return true;
            }
            $visible = preg_replace(self::INVISIBLE_CHARACTERS, '', $normalized);
            if (null === $visible) {
                return true;
            }
            $match = preg_match($pattern, $visible);
            if (false === $match || 1 === $match) {
                return true;
            }
        }

        return false;
    }
}
