<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\LocalPackageId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalPackageIdTest extends TestCase
{
    private const string PACKAGE = '0197a3c0-0000-7abc-8def-00000000d5e1';

    #[Test]
    public function anUppercaseIdIsKeptInLowercase(): void
    {
        $packageId = new LocalPackageId(strtoupper(self::PACKAGE));

        self::assertSame(self::PACKAGE, $packageId->toString());
        self::assertTrue($packageId->equals(new LocalPackageId(self::PACKAGE)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function variantNibblesOfAUuid(): iterable
    {
        yield '8' => ['0197a3c0-0000-7abc-8def-00000000d5e1'];
        yield '9' => ['0197a3c0-0000-7abc-9def-00000000d5e1'];
        yield 'a' => ['0197a3c0-0000-7abc-adef-00000000d5e1'];
        yield 'b' => ['0197a3c0-0000-7abc-bdef-00000000d5e1'];
    }

    #[Test]
    #[DataProvider('variantNibblesOfAUuid')]
    public function everyVariantNibbleOfARfcUuidIsAccepted(string $value): void
    {
        self::assertSame($value, new LocalPackageId($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNoCanonicalUuidV7(): iterable
    {
        yield 'a UUIDv4' => ['0197a3c0-0000-4abc-8def-00000000d5e1'];
        yield 'variant nibble c' => ['0197a3c0-0000-7abc-cdef-00000000d5e1'];
        yield 'a trailing line feed' => [self::PACKAGE."\n"];
        yield 'a trailing CRLF' => [self::PACKAGE."\r\n"];
        yield 'empty' => [''];
        yield '37 characters' => [self::PACKAGE.'0'];
    }

    #[Test]
    #[DataProvider('valuesThatAreNoCanonicalUuidV7')]
    public function aValueThatIsNoCanonicalUuidV7IsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A laptop package id must be a canonical UUIDv7.');

        new LocalPackageId($value);
    }

    #[Test]
    public function idsThatDifferInOneCharacterAreNotEqual(): void
    {
        self::assertFalse(new LocalPackageId(self::PACKAGE)->equals(new LocalPackageId('0197a3c0-0000-7abc-8def-00000000d5e2')));
    }
}
