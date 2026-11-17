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
     * Format characters (zero-width space, joiners, soft hyphen, BOM) are removed first, so they cannot split a word
     * past the list. Boundaries are Unicode letters and digits, so "hackö" is not "hack".
     */
    private const string FORMAT_CHARACTERS = '/\p{Cf}+/u';

    /**
     * True when the security word list matches the title, the detail or any pin note. A text that is not valid UTF-8
     * cannot be checked, so it counts as sensitive: the check fails closed.
     */
    public function isSecuritySensitive(DraftReport $draft): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', WordLists::SECURITY).')(?![\p{L}\p{N}])/iu';
        foreach ([$draft->title, $draft->detail, ...$draft->pinNotes] as $text) {
            $visible = preg_replace(self::FORMAT_CHARACTERS, '', $text);
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
