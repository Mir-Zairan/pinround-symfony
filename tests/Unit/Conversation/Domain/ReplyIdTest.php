<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\ReplyId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplyIdTest extends TestCase
{
    #[Test]
    public function anIdIsKeptInLowercaseAndEqualsItsOtherCase(): void
    {
        $id = new ReplyId('0197A3C0-1234-7ABC-BDEF-0123456789AB');

        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', $id->toString());
        self::assertTrue($id->equals(new ReplyId('0197a3c0-1234-7abc-bdef-0123456789ab')));
        self::assertFalse($id->equals(new ReplyId('0197a3c0-1234-7abc-bdef-0123456789ac')));
    }

    #[Test]
    public function anIdThatIsNotACanonicalUuidV7IsRefused(): void
    {
        foreach (['', '0197a3c0-1234-4abc-8def-0123456789ab', '0197a3c0-1234-7abc-cdef-0123456789ab', "0197a3c0-1234-7abc-8def-0123456789ab\n"] as $value) {
            try {
                new ReplyId($value);
                self::fail(\sprintf('Not refused: %s', json_encode($value)));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('A reply id must be a canonical UUIDv7.', $exception->getMessage());
            }
        }
    }
}
