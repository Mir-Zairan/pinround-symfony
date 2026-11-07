<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Tracing;

use App\SharedKernel\Infrastructure\Tracing\SqlKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqlKindTest extends TestCase
{
    #[Test]
    #[DataProvider('statements')]
    public function itReadsOnlyTheFirstKeyword(string $sql, string $expectedKind): void
    {
        self::assertSame($expectedKind, SqlKind::of($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT t0.id FROM ticket t0 WHERE t0.id = ?', 'SELECT'];
        yield 'lower-case insert' => ['insert into ticket (id) values (?)', 'INSERT'];
        yield 'leading whitespace' => ["  \n\t UPDATE ticket SET title = ?", 'UPDATE'];
        yield 'line comment first' => ["-- why we ask\nDELETE FROM ticket", 'DELETE'];
        yield 'block comment first' => ['/* why */ /* more */ SELECT 1', 'SELECT'];
        yield 'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', 'WITH'];
        yield 'begin' => ['BEGIN', 'BEGIN'];
        yield 'savepoint' => ['SAVEPOINT doctrine_savepoint_1', 'SAVEPOINT'];
        yield 'set' => ["SET statement_timeout = '5s'", 'SET'];
        yield 'unknown keyword' => ['FROBNICATE secret_table', 'OTHER'];
        yield 'a word that is a table name' => ['secret_table', 'OTHER'];
        yield 'opening parenthesis' => ['(SELECT 1) UNION (SELECT 2)', 'OTHER'];
        yield 'empty' => ['', 'OTHER'];
        yield 'only whitespace' => ["  \n", 'OTHER'];
        yield 'only a comment' => ['-- nothing', 'OTHER'];
        yield 'unterminated block comment' => ['/* never closed SELECT 1', 'OTHER'];
    }
}
