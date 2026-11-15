<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain;

use App\Notification\Domain\PingId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingIdTest extends TestCase
{
    #[Test]
    public function anIdIsKeptInLowercaseAndEqualsItsOtherCase(): void
    {
        $id = new PingId('0197A3C0-1234-7ABC-BDEF-0123456789AB');

        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', $id->toString());
        self::assertTrue($id->equals(new PingId('0197a3c0-1234-7abc-bdef-0123456789ab')));
        self::assertFalse($id->equals(new PingId('0197a3c0-1234-7abc-bdef-0123456789ac')));
    }

    #[Test]
    public function anIdThatIsNotACanonicalUuidV7IsRefused(): void
    {
        foreach (['', '0197a3c0-1234-4abc-8def-0123456789ab', '0197a3c0-1234-7abc-cdef-0123456789ab', "0197a3c0-1234-7abc-8def-0123456789ab\n"] as $value) {
            try {
                new PingId($value);
                self::fail(\sprintf('Not refused: %s', json_encode($value)));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('A ping id must be a canonical UUIDv7.', $exception->getMessage());
            }
        }
    }
}
