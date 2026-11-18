<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\IntakeCheckId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntakeCheckIdTest extends TestCase
{
    private const string CHECK = '0197a3c0-0000-7abc-8def-00000000c4e1';

    #[Test]
    public function anUppercaseIdIsKeptInLowercase(): void
    {
        $checkId = new IntakeCheckId(strtoupper(self::CHECK));

        self::assertSame(self::CHECK, $checkId->toString());
        self::assertTrue($checkId->equals(new IntakeCheckId(self::CHECK)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function variantNibblesOfAUuid(): iterable
    {
        yield '8' => ['0197a3c0-0000-7abc-8def-00000000c4e1'];
        yield '9' => ['0197a3c0-0000-7abc-9def-00000000c4e1'];
        yield 'a' => ['0197a3c0-0000-7abc-adef-00000000c4e1'];
        yield 'b' => ['0197a3c0-0000-7abc-bdef-00000000c4e1'];
    }

    #[Test]
    #[DataProvider('variantNibblesOfAUuid')]
    public function everyVariantNibbleOfARfcUuidIsAccepted(string $value): void
    {
        self::assertSame($value, new IntakeCheckId($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNoCanonicalUuidV7(): iterable
    {
        yield 'a UUIDv4' => ['0197a3c0-0000-4abc-8def-00000000c4e1'];
        yield 'variant nibble c' => ['0197a3c0-0000-7abc-cdef-00000000c4e1'];
        yield 'a trailing line feed' => [self::CHECK."\n"];
        yield 'a trailing CRLF' => [self::CHECK."\r\n"];
        yield 'empty' => [''];
        yield '37 characters' => [self::CHECK.'0'];
    }

    #[Test]
    #[DataProvider('valuesThatAreNoCanonicalUuidV7')]
    public function aValueThatIsNoCanonicalUuidV7IsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('An intake check id must be a canonical UUIDv7.');

        new IntakeCheckId($value);
    }

    #[Test]
    public function idsThatDifferInOneCharacterAreNotEqual(): void
    {
        self::assertFalse(new IntakeCheckId(self::CHECK)->equals(new IntakeCheckId('0197a3c0-0000-7abc-8def-00000000c4e2')));
    }
}
