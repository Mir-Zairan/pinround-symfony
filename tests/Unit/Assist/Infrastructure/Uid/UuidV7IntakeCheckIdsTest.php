<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Uid;

use App\Assist\Infrastructure\Uid\UuidV7IntakeCheckIds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

final class UuidV7IntakeCheckIdsTest extends TestCase
{
    #[Test]
    public function eachIdIsANewUuidV7OfTheClocksTime(): void
    {
        $intakeCheckIds = new UuidV7IntakeCheckIds(new MockClock('2027-03-15 10:00:00.123 UTC'));

        $first = $intakeCheckIds->next();
        $second = $intakeCheckIds->next();

        self::assertFalse($first->equals($second));
        foreach ([$first, $second] as $checkId) {
            self::assertSame('2027-03-15 10:00:00.123', UuidV7::fromString($checkId->toString())->getDateTime()->format('Y-m-d H:i:s.v'));
        }
    }
}
