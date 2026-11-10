<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Domain;

use App\SharedKernel\Domain\TicketId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketIdTest extends TestCase
{
    #[Test]
    public function itNormalisesUppercaseToLowercase(): void
    {
        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', new TicketId('0197A3C0-1234-7ABC-BDEF-0123456789AB')->toString());
    }

    #[Test]
    public function idsWithTheSameValueInAnyCaseAreEqual(): void
    {
        $ticketId = new TicketId('0197a3c0-1234-7abc-8def-0123456789ab');

        self::assertTrue($ticketId->equals(new TicketId('0197A3C0-1234-7ABC-8DEF-0123456789AB')));
        self::assertFalse($ticketId->equals(new TicketId('0197a3c0-1234-7abc-8def-0123456789ac')));
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
        yield 'sql' => ["0197a3c0-1234-7abc-8def-0123456789ab'; --"];
    }

    #[Test]
    #[DataProvider('notACanonicalUuidV7')]
    public function itRejectsAnythingButACanonicalUuidV7(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ticket id must be a canonical UUIDv7.');

        new TicketId($value);
    }
}
