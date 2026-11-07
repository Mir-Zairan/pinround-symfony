<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserIdTest extends TestCase
{
    #[Test]
    public function itNormalisesUppercaseToLowercase(): void
    {
        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', new UserId('0197A3C0-1234-7ABC-BDEF-0123456789AB')->toString());
    }

    #[Test]
    public function idsWithTheSameValueInAnyCaseAreEqual(): void
    {
        $userId = new UserId('0197a3c0-1234-7abc-8def-0123456789ab');

        self::assertTrue($userId->equals(new UserId('0197A3C0-1234-7ABC-8DEF-0123456789AB')));
        self::assertFalse($userId->equals(new UserId('0197a3c0-1234-7abc-8def-0123456789ac')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notACanonicalUuidV7(): iterable
    {
        yield 'empty' => [''];
        yield 'version 4' => ['0197a3c0-1234-4abc-8def-0123456789ab'];
        yield 'wrong variant' => ['0197a3c0-1234-7abc-cdef-0123456789ab'];
        yield 'trailing newline' => ["0197a3c0-1234-7abc-8def-0123456789ab\n"];
        yield 'not hex' => ['0197a3c0-1234-7abc-8def-0123456789ag'];
    }

    #[Test]
    #[DataProvider('notACanonicalUuidV7')]
    public function itRejectsAnythingButACanonicalUuidV7(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UserId($value);
    }
}
