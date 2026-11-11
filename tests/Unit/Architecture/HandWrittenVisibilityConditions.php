<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

/**
 * Finds the hand-written visibility conditions in src/ PHP files, which must go through TicketVisibilitySql instead
 * (ADR-0013, P03.T2): SQL (and DQL) in string literals that compares the visibility column, cast or not, or switches
 * on it with CASE, and a 'visibility' filter given to a repository's findBy, findOneBy or count, or to a Criteria
 * expression. Comments and other PHP code are not read, so `$this->visibility = $visibility` passes. Migrations live
 * outside src/ and are not scanned.
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
        .'|\bCASE\s+(?:\S+\.)?visibility\s+WHEN\b/i';

    private const array STRING_TOKENS = [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE];

    /** The repository methods whose array argument filters rows by its keys, in lower case. */
    private const array FILTER_BY_KEY_CALLS = ['findby', 'findoneby', 'count'];

    /** The Criteria ExpressionBuilder methods whose first argument names the filtered field, in lower case. */
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
        // The name of the call each open parenthesis belongs to, innermost last; null for a parenthesis of no call.
        $openCalls = [];
        foreach ($tokens as $index => $token) {
            if ('(' === $token) {
                $openCalls[] = self::callNameBefore($tokens, $index, $openCalls);
                continue;
            }
            if (')' === $token) {
                array_pop($openCalls);
                continue;
            }
            if (!\is_array($token) || !\in_array($token[0], self::STRING_TOKENS, true)) {
                continue;
            }
            preg_match_all(self::SQL_CONDITION, $token[1], $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $offset]) {
                $found[] = \sprintf('%s:%d: %s', $path, $token[2] + substr_count($token[1], "\n", 0, $offset), $text);
            }
            if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && 'visibility' === strtolower(trim($token[1], '\'"'))) {
                $filter = self::filterOn($token, $tokens[$index - 1] ?? null, $tokens[$index + 1] ?? null, $openCalls);
                if (null !== $filter) {
                    $found[] = \sprintf('%s:%d: %s', $path, $token[2], $filter);
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param list<?string>                        $openCalls
     */
    private static function callNameBefore(array $tokens, int $index, array $openCalls): ?string
    {
        $before = $tokens[$index - 1] ?? null;
        if (!\is_array($before)) {
            return null;
        }
        if (\T_ARRAY === $before[0]) {
            // array( ... ) is no call: its keys belong to the call around it.
            return self::innermost($openCalls);
        }

        return \T_STRING === $before[0] ? $before[1] : null;
    }

    /**
     * @param array{int, string, int}             $token     the 'visibility' string literal
     * @param array{int, string, int}|string|null $previous
     * @param array{int, string, int}|string|null $next
     * @param list<?string>                       $openCalls
     *
     * @return ?string the text to report, or null when the literal filters nothing
     */
    private static function filterOn(array $token, array|string|null $previous, array|string|null $next, array $openCalls): ?string
    {
        $call = self::innermost($openCalls);
        $callKey = strtolower($call ?? '');
        if (\is_array($next) && \T_DOUBLE_ARROW === $next[0] && \in_array($callKey, self::FILTER_BY_KEY_CALLS, true)) {
            return $token[1].' =>';
        }
        if ('(' === $previous && \in_array($callKey, self::FILTER_BY_FIELD_CALLS, true)) {
            return $call.'('.$token[1];
        }

        return null;
    }

    /**
     * @param list<?string> $openCalls
     */
    private static function innermost(array $openCalls): ?string
    {
        return [] === $openCalls ? null : $openCalls[\count($openCalls) - 1];
    }
}
