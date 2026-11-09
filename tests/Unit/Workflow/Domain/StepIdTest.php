<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\StepId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepIdTest extends TestCase
{
    #[Test]
    public function itNormalisesUppercaseToLowercase(): void
    {
        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', new StepId('0197A3C0-1234-7ABC-BDEF-0123456789AB')->toString());
    }

    #[Test]
    public function idsWithTheSameValueInAnyCaseAreEqual(): void
    {
        $stepId = new StepId('0197a3c0-1234-7abc-8def-0123456789ab');

        self::assertTrue($stepId->equals(new StepId('0197A3C0-1234-7ABC-8DEF-0123456789AB')));
        self::assertFalse($stepId->equals(new StepId('0197a3c0-1234-7abc-8def-0123456789ac')));
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
        yield 'trailing newline' => ["0197a3c0-1234-7abc-8def-0123456789ab\n"];
    }

    #[Test]
    #[DataProvider('notACanonicalUuidV7')]
    public function itRejectsAnythingButACanonicalUuidV7(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A step id must be a canonical UUIDv7.');

        new StepId($value);
    }
}
