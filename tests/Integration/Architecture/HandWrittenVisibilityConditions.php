<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

/**
 * Finds SQL (and DQL) in the string literals of src/ PHP files that compares the visibility column, the hand-written
 * visibility conditions that must go through TicketVisibilitySql instead (ADR-0013, P03.T2). Comments and PHP code are
 * not read, so `$this->visibility = $visibility` passes. Migrations live outside src/ and are not scanned.
 */
final class HandWrittenVisibilityConditions
{
    /**
     * The files that may name the column in a comparison, with the reason.
     */
    public const array ALLOWED = [
        'src/Ticketing/Infrastructure/Doctrine/TicketVisibilitySql.php' => 'renders the canonical predicate',
        'src/Insight/Infrastructure/Doctrine/DbalTurnProjector.php' => 'copies the column into ins_ticket_turn (SET visibility = EXCLUDED.visibility); it writes the column and filters nothing',
    ];

    private const string COMPARISON = '/\bvisibility\s*(?:=|<>|!=|\bNOT\s+IN\b|\bIN\b|\bIS\b)|(?:=|<>|!=)\s*(?:\w+\.)?visibility\b/i';

    private const array STRING_TOKENS = [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE];

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
     * @return list<string> `path:line: text` of each hand-written condition in the source's string literals
     */
    public static function inSource(string $path, string $source): array
    {
        $found = [];
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || !\in_array($token[0], self::STRING_TOKENS, true)) {
                continue;
            }
            preg_match_all(self::COMPARISON, $token[1], $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $offset]) {
                $found[] = \sprintf('%s:%d: %s', $path, $token[2] + substr_count($token[1], "\n", 0, $offset), $text);
            }
        }

        return $found;
    }
}
