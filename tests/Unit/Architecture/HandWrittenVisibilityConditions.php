<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

/**
 * Finds the hand-written visibility conditions in src/ PHP files, which must go through TicketVisibilitySql instead
 * (ADR-0013, P03.T2): SQL (and DQL) in string literals that compares the visibility column, cast with :: or CAST or
 * not, or switches on it with CASE; a 'visibility' key in the first argument of a findBy, findOneBy or count method
 * call; and 'visibility' or 'alias.visibility' as the first argument of an expression method such as eq or in.
 * Comments and other PHP code are not read, so `$this->visibility = $visibility`, a sort key, a CAST in a SELECT list
 * and PHP's own count() pass. Migrations live outside src/ and are not scanned.
 */
final class HandWrittenVisibilityConditions
{
    /**
     * The files that may name the column in a comparison, with the reason.
     */
    public const array ALLOWED = [
        'src/Ticketing/Api/TicketVisibilitySql.php' => 'renders the canonical predicate',
        'src/Insight/Infrastructure/Doctrine/DbalTurnProjector.php' => 'copies the column into ins_ticket_turn (SET visibility = EXCLUDED.visibility); it writes the column and filters nothing',
    ];

    private const string SQL_CONDITION = '/\bvisibility(?:\s*::\s*\w+)?\s*(?:=|<>|!=|\bNOT\s+IN\b|\bIN\b|\bIS\b)'
        .'|(?:=|<>|!=)\s*(?:\w+\.)?visibility\b'
        .'|\bCAST\s*\(\s*(?:\w+\.)?visibility\s+AS\s+\w+\s*\)\s*(?:=|<>|!=|\bNOT\s+IN\b|\bIN\b|\bIS\b)'
        .'|(?:=|<>|!=)\s*CAST\s*\(\s*(?:\w+\.)?visibility\b'
        .'|\bCASE\s+(?:\S+\.)?visibility\s+WHEN\b/i';

    /** A string literal that names the column, with or without a query alias, quotes included. */
    private const string COLUMN_LITERAL = '/^([\'"])(?:\w+\.)?visibility\1$/i';

    private const array STRING_TOKENS = [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE];

    /** The repository methods whose first argument, an array, filters rows by its keys, in lower case. */
    private const array FILTER_BY_KEY_CALLS = ['findby', 'findoneby', 'count'];

    /** The Criteria and query ExpressionBuilder methods whose first argument names the filtered field, in lower case. */
    private const array FILTER_BY_FIELD_CALLS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'notin', 'isnull', 'isnotnull', 'contains', 'memberof', 'startswith', 'endswith'];

    /**
     * @return list<string> `path:line: text` of each hand-written condition in src/, outside the allowed files
     */
    public static function inRepository(string $root): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $path = substr($file->getPathname(), \strlen($root) + 1);
            if ('php' !== $file->getExtension() || \array_key_exists($path, self::ALLOWED)) {
                continue;
            }
            $found = [...$found, ...self::inSource($path, (string) file_get_contents($file->getPathname()))];
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> `path:line: text` of each hand-written condition in the source, in source order
     */
    public static function inSource(string $path, string $source): array
    {
        $found = [];
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        // Each open parenthesis or bracket, innermost last: the method it calls (null for none, such as a PHP function
        // like count()), whether it opens an array literal, and the index of the argument or element being read.
        $openFrames = [];
        foreach ($tokens as $index => $token) {
            if ('(' === $token || '[' === $token || (\is_array($token) && \T_ATTRIBUTE === $token[0])) {
                $openFrames[] = self::frameOpenedAt($tokens, $index);
                continue;
            }
            if (')' === $token || ']' === $token) {
                array_pop($openFrames);
                continue;
            }
            if (',' === $token && [] !== $openFrames) {
                $innermost = array_pop($openFrames);
                $openFrames[] = ['method' => $innermost['method'], 'isArray' => $innermost['isArray'], 'argument' => $innermost['argument'] + 1];
                continue;
            }
            if (!\is_array($token) || !\in_array($token[0], self::STRING_TOKENS, true)) {
                continue;
            }
            preg_match_all(self::SQL_CONDITION, $token[1], $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $offset]) {
                $found[] = \sprintf('%s:%d: %s', $path, $token[2] + substr_count($token[1], "\n", 0, $offset), $text);
            }
            if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match(self::COLUMN_LITERAL, $token[1])) {
                $filter = self::filterOn($token, $tokens[$index - 1] ?? null, $tokens[$index + 1] ?? null, $openFrames);
                if (null !== $filter) {
                    $found[] = \sprintf('%s:%d: %s', $path, $token[2], $filter);
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{method: ?string, isArray: bool, argument: int}
     */
    private static function frameOpenedAt(array $tokens, int $index): array
    {
        $opener = $tokens[$index];
        $before = $tokens[$index - 1] ?? null;
        $beforeThat = $tokens[$index - 2] ?? null;
        // An offset such as $row['visibility'] counts as an array too: no key of one is followed by =>.
        $isArray = '[' === $opener || ('(' === $opener && \is_array($before) && \T_ARRAY === $before[0]);
        $isMethodCall = '(' === $opener && \is_array($before) && \T_STRING === $before[0]
            && \is_array($beforeThat) && \in_array($beforeThat[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);

        return ['method' => $isMethodCall ? $before[1] : null, 'isArray' => $isArray, 'argument' => 0];
    }

    /**
     * @param array{int, string, int}                                    $token      the string literal naming the column
     * @param array{int, string, int}|string|null                        $previous
     * @param array{int, string, int}|string|null                        $next
     * @param list<array{method: ?string, isArray: bool, argument: int}> $openFrames
     *
     * @return ?string the text to report, or null when the literal filters nothing
     */
    private static function filterOn(array $token, array|string|null $previous, array|string|null $next, array $openFrames): ?string
    {
        $innermost = $openFrames[\count($openFrames) - 1] ?? null;
        if (null === $innermost) {
            return null;
        }
        $around = $openFrames[\count($openFrames) - 2] ?? null;
        if (\is_array($next) && \T_DOUBLE_ARROW === $next[0] && $innermost['isArray'] && null !== $around
            && 0 === $around['argument'] && \in_array(strtolower($around['method'] ?? ''), self::FILTER_BY_KEY_CALLS, true)) {
            return $token[1].' =>';
        }
        if ('(' === $previous && \in_array(strtolower($innermost['method'] ?? ''), self::FILTER_BY_FIELD_CALLS, true)) {
            return $innermost['method'].'('.$token[1];
        }

        return null;
    }
}
