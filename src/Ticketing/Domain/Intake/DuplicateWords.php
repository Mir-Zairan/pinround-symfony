<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * The word rule of duplicate suggestions (FR-INT-15, report-a-problem.md, Duplicate suggestions): a report's words are
 * those of its title, detail and pin notes, in lowercase, letters and digits only, longer than three characters and
 * not a stop word. An open ticket is a possible duplicate when its title shares at least three of them, and they are at
 * least 30 % of its own title words.
 */
final class DuplicateWords
{
    /** Suggestions start once the title has this many characters, trimmed. */
    public const int MIN_TITLE_LENGTH = 8;
    public const int MIN_SHARED_WORDS = 3;
    public const float MIN_SHARED_SHARE = 0.3;

    private const array STOP_WORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'when', 'what', 'have', 'than', 'more', 'example', 'made', 'demo', 'into', 'there', 'they', 'looks', 'shows'];

    private function __construct()
    {
    }

    /**
     * @return list<string> each word once, in the order first met
     */
    public static function of(string ...$texts): array
    {
        $words = [];
        foreach ($texts as $text) {
            $split = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);
            foreach (false === $split ? [] : $split as $word) {
                if (mb_strlen($word) > 3 && !\in_array($word, self::STOP_WORDS, true)) {
                    $words[$word] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($words));
    }

    /**
     * @param list<string> $reportWords as of() gives them
     *
     * @return int|null how many words the title shares with the report, or null when that is not enough for a match
     */
    public static function sharedWith(array $reportWords, string $candidateTitle): ?int
    {
        $titleWords = self::of($candidateTitle);
        if ([] === $titleWords) {
            return null;
        }
        $shared = \count(array_intersect($titleWords, $reportWords));

        return $shared >= self::MIN_SHARED_WORDS && $shared / \count($titleWords) >= self::MIN_SHARED_SHARE ? $shared : null;
    }
}
