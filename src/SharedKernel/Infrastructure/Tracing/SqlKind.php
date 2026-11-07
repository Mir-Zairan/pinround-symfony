<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

/**
 * The only thing a database span says about its statement: the first SQL keyword, from a fixed list. Never the
 * text, a value or a table name (ADR-0029); a statement that starts with anything else is OTHER.
 */
final class SqlKind
{
    public const string BEGIN = 'BEGIN';
    public const string COMMIT = 'COMMIT';
    public const string ROLLBACK = 'ROLLBACK';
    public const string OTHER = 'OTHER';

    private const array KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WITH', 'VALUES', 'BEGIN', 'START', 'COMMIT', 'ROLLBACK',
        'SAVEPOINT', 'RELEASE', 'SET', 'RESET', 'SHOW', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'COPY', 'LOCK',
        'EXPLAIN', 'CALL', 'DO', 'GRANT', 'REVOKE', 'ANALYZE', 'VACUUM', 'LISTEN', 'NOTIFY',
    ];

    /** Leading whitespace and comments are skipped before the keyword is read. */
    public static function of(string $sql): string
    {
        if (1 !== preg_match('/\A(?>\s++|--[^\n]*+(?:\n|\z)|\/\*.*?\*\/)*+([A-Za-z]+)/s', $sql, $match)) {
            return self::OTHER;
        }

        $keyword = strtoupper($match[1]);

        return \in_array($keyword, self::KEYWORDS, true) ? $keyword : self::OTHER;
    }

    private function __construct()
    {
    }
}
