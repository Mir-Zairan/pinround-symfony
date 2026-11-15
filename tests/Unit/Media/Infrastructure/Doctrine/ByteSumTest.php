<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Doctrine;

use App\Media\Infrastructure\Doctrine\ByteSum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ByteSumTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function sums(): iterable
    {
        yield 'numeric text, as PostgreSQL sums bigint' => ['53687091200', 53_687_091_200];
        yield 'zero as text, the COALESCE of no rows' => ['0', 0];
        yield 'an int, as another driver may give it' => [2048, 2048];
        yield 'eighteen digits' => ['999999999999999999', 999_999_999_999_999_999];
    }

    #[Test]
    #[DataProvider('sums')]
    public function aSumOfBytesIsReadAsAnInt(mixed $sum, int $bytes): void
    {
        self::assertSame($bytes, ByteSum::of($sum));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notSums(): iterable
    {
        yield 'null, as SUM of no rows without COALESCE' => [null];
        yield 'empty text' => [''];
        yield 'a negative int' => [-1];
        yield 'negative text' => ['-1'];
        yield 'a fraction' => ['1.5'];
        yield 'a float' => [1.0];
        yield 'a trailing newline' => ["2048\n"];
        yield 'nineteen digits, past what an int holds for sure' => ['9999999999999999999'];
    }

    #[Test]
    #[DataProvider('notSums')]
    public function anythingElseIsRefusedRatherThanCountedAsNoBytes(mixed $sum): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('A sum of bytes must be a whole number of 0 or more of at most 18 digits.');

        ByteSum::of($sum);
    }
}
