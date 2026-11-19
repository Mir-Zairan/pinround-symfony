<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * The check reasons of a draft report (FR-INT-07 to FR-INT-09, report-a-problem.md): the one rule the live preview and
 * the create both use, so the preview never says something the create does not do. It also answers whether a draft is
 * security sensitive, which makes it team-only and keeps it away from cloud AI.
 */
final readonly class CheckReasons
{
    /**
     * Format characters and every default-ignorable code point (zero-width space, joiners, soft hyphen, BOM, variation
     * selectors, the combining grapheme joiner, Hangul fillers) are removed, so an invisible character cannot split a
     * word past the list. So are the blank symbols Unicode counts as neither: the Braille blank U+2800, the Khitan
     * filler U+16FE4 and the musical null notehead U+1D159.
     */
    private const string INVISIBLE_CHARACTERS = '/[\p{Cf}\p{DI}\x{2800}\x{16FE4}\x{1D159}]+/u';

    /** The detail is short below this many characters, once trimmed. */
    public const int SHORT_DETAIL_LENGTH = 40;

    /**
     * The reasons in the order the preview sentence names them: dupe, info, team, risk, rule.
     *
     * @return list<CheckReason>
     */
    public function for(DraftReport $draft, ReportContext $context): array
    {
        $reasons = [];
        if ($context->wasDuplicateShown) {
            $reasons[] = CheckReason::Duplicate;
        }
        if ($context->aiSaysInfoIsMissing || (mb_strlen(trim($draft->detail)) < self::SHORT_DETAIL_LENGTH && 0 === $context->pinCount)) {
            $reasons[] = CheckReason::LittleInfo;
        }
        if (!$context->hasTeam) {
            $reasons[] = CheckReason::NoTeam;
        }
        if ($context->aiSaysRisky || $this->matches($draft, WordLists::RISK)) {
            $reasons[] = CheckReason::Risky;
        }
        if ($context->everyNewTicketNeedsACheck) {
            $reasons[] = CheckReason::CompanyRule;
        }

        return $reasons;
    }

    /**
     * True when the security word list matches the title, the detail or any pin note. Each text is NFKC-normalized
     * first, so full-width and other compatibility letters read as the plain ones. A text that is not valid UTF-8
     * cannot be checked, so it counts as sensitive: the check fails closed.
     */
    public function isSecuritySensitive(DraftReport $draft): bool
    {
        return $this->matches($draft, WordLists::SECURITY);
    }

    /**
     * True when one entry of $words matches the title, the detail or any pin note. Each text is NFKC-normalized first,
     * so full-width and other compatibility letters read as the plain ones. A text that is not valid UTF-8 cannot be
     * checked, so it counts as a match: the check fails closed.
     *
     * @param list<string> $words regex fragments, as WordLists keeps them
     */
    private function matches(DraftReport $draft, array $words): bool
    {
        // A letter or digit on either side ends a match, so "hackö", "token2" and "untoken" are not list words.
        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', $words).')(?![\p{L}\p{N}])/iu';
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
