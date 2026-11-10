<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\Ticketing\Domain\ProjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectIdTest extends TestCase
{
    #[Test]
    public function itNormalisesUppercaseToLowercase(): void
    {
        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', new ProjectId('0197A3C0-1234-7ABC-BDEF-0123456789AB')->toString());
    }

    #[Test]
    public function idsWithTheSameValueInAnyCaseAreEqual(): void
    {
        $projectId = new ProjectId('0197a3c0-1234-7abc-8def-0123456789ab');

        self::assertTrue($projectId->equals(new ProjectId('0197A3C0-1234-7ABC-8DEF-0123456789AB')));
        self::assertFalse($projectId->equals(new ProjectId('0197a3c0-1234-7abc-8def-0123456789ac')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notACanonicalUuidV7(): iterable
    {
        yield 'empty' => [''];
        yield 'version 4' => ['0197a3c0-1234-4abc-8def-0123456789ab'];
        yield 'wrong variant' => ['0197a3c0-1234-7abc-cdef-0123456789ab'];
        yield 'no hyphens' => ['0197a3c012347abc8def0123456789ab'];
        yield 'braces' => ['{0197a3c0-1234-7abc-8def-0123456789ab}'];
        yield 'trailing newline' => ["0197a3c0-1234-7abc-8def-0123456789ab\n"];
        yield 'sql' => ["0197a3c0-1234-7abc-8def-0123456789ab'; --"];
    }

    #[Test]
    #[DataProvider('notACanonicalUuidV7')]
    public function itRejectsAnythingButACanonicalUuidV7(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A project id must be a canonical UUIDv7.');

        new ProjectId($value);
    }
}
