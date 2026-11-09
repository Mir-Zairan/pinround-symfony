<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\Ticketing\Domain\InvalidProjectKey;
use App\Ticketing\Domain\ProjectKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        yield 'four letters' => ['SHOP'];
        yield 'two letters, the shortest' => ['AB'];
        yield 'a letter and a digit' => ['S1'];
        yield 'ten characters, the longest' => ['SHOPPINGC1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'small letters' => ['shop'];
        yield 'starts with a digit' => ['1SHOP'];
        yield 'eleven characters' => ['SHOPPINGCART1'];
        yield 'one letter' => ['S'];
        yield 'a dash' => ['SH-OP'];
        yield 'empty' => [''];
        yield 'a trailing newline' => ["SHOP\n"];
        yield 'a space' => ['SH OP'];
        yield 'a capital letter outside A to Z' => ['ÄBC'];
    }

    #[Test]
    #[DataProvider('validKeys')]
    public function itAcceptsTwoToTenCapitalLettersOrDigitsStartingWithALetter(string $key): void
    {
        self::assertSame($key, new ProjectKey($key)->toString());
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function itRejectsAnyOtherKeyWithAPlainMessage(string $key): void
    {
        $this->expectException(InvalidProjectKey::class);
        $this->expectExceptionMessage('A project key is 2 to 10 capital letters or digits and starts with a letter.');

        new ProjectKey($key);
    }

    #[Test]
    public function keysWithTheSameLettersAreEqual(): void
    {
        self::assertTrue(new ProjectKey('SHOP')->equals(new ProjectKey('SHOP')));
        self::assertFalse(new ProjectKey('SHOP')->equals(new ProjectKey('SHOP2')));
    }
}
