<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\Ticketing\Domain\Priority;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriorityTest extends TestCase
{
    #[Test]
    public function theRankRunsFromZeroForABlockerToFourForAMinorPriority(): void
    {
        self::assertSame(
            ['blocker' => 0, 'critical' => 1, 'major' => 2, 'normal' => 3, 'minor' => 4],
            array_combine(
                array_map(static fn (Priority $priority): string => $priority->value, Priority::cases()),
                array_map(static fn (Priority $priority): int => $priority->rank(), Priority::cases()),
            ),
        );
    }
}
