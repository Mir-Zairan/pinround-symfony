<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Doctrine;

use App\Organization\Infrastructure\Doctrine\TextArrayType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextArrayTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function listsAndTheirLiterals(): iterable
    {
        yield 'empty' => [[], '{}'];
        yield 'plain words' => [['admin', 'manager'], '{"admin","manager"}'];
        yield 'quote, backslash, comma, brace and space' => [['a "b"', 'c\\d', 'e,f', '{g}', ' h '], '{"a \"b\"","c\\\\d","e,f","{g}"," h "}'];
        yield 'the word NULL as text' => [['NULL'], '{"NULL"}'];
        yield 'empty string' => [[''], '{""}'];
    }

    /**
     * @param list<string> $list
     */
    #[Test]
    #[DataProvider('listsAndTheirLiterals')]
    public function aListBecomesAQuotedArrayLiteralAndComesBackTheSame(array $list, string $literal): void
    {
        $type = new TextArrayType();
        $platform = new PostgreSQLPlatform();

        self::assertSame($literal, $type->convertToDatabaseValue($list, $platform));
        self::assertSame($list, $type->convertToPHPValue($literal, $platform));
    }

    #[Test]
    public function unquotedElementsAsPostgresqlPrintsThemAreRead(): void
    {
        self::assertSame(['admin', 'manager'], new TextArrayType()->convertToPHPValue('{admin,manager}', new PostgreSQLPlatform()));
    }

    #[Test]
    public function nullStaysNull(): void
    {
        $type = new TextArrayType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAOneDimensionalTextArrayWithoutNulls(): iterable
    {
        yield 'no braces' => ['admin'];
        yield 'a null element' => ['{admin,NULL}'];
        yield 'two dimensions' => ['{{admin}}'];
        yield 'unterminated quote' => ['{"admin}'];
    }

    #[Test]
    #[DataProvider('notAOneDimensionalTextArrayWithoutNulls')]
    public function anythingButAOneDimensionalArrayWithoutNullsIsRefused(string $literal): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new TextArrayType()->convertToPHPValue($literal, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notAListOfStrings(): iterable
    {
        yield 'a string' => ['admin'];
        yield 'an integer element' => [['admin', 1]];
        yield 'a null element' => [['admin', null]];
    }

    #[Test]
    #[DataProvider('notAListOfStrings')]
    public function anythingButAListOfStringsIsRefusedOnTheWayIn(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TextArrayType()->convertToDatabaseValue($value, new PostgreSQLPlatform());
    }

    #[Test]
    public function theColumnIsTextArrayAndPostgresqlsArrayTypeMapsBackToIt(): void
    {
        $type = new TextArrayType();
        $platform = new PostgreSQLPlatform();

        self::assertSame('TEXT[]', $type->getSQLDeclaration([], $platform));
        self::assertSame(['_text'], $type->getMappedDatabaseTypes($platform));
    }
}
