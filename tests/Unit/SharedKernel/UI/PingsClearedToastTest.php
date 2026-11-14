<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\SharedKernel\UI\PingsClearedToast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingsClearedToastTest extends TestCase
{
    private const string TOAST = 'Code approved. Lena’s turn to test.';

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function clearedCounts(): iterable
    {
        yield 'none cleared leaves the toast as it is' => [0, self::TOAST];
        yield 'one ping' => [1, self::TOAST.' 1 ping on this ticket cleared.'];
        yield 'two pings' => [2, self::TOAST.' 2 pings on this ticket cleared.'];
        yield 'twelve pings' => [12, self::TOAST.' 12 pings on this ticket cleared.'];
    }

    #[Test]
    #[DataProvider('clearedCounts')]
    public function theToastEndsWithHowManyPingsTheActionCleared(int $clearedCount, string $expected): void
    {
        self::assertSame($expected, PingsClearedToast::withClearedPings(self::TOAST, $clearedCount));
    }

    #[Test]
    public function aNegativeCountIsABug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A cleared ping count is 0 or more, -1 given.');

        PingsClearedToast::withClearedPings(self::TOAST, -1);
    }
}
