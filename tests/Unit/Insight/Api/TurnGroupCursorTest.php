<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Api;

use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnGroupCursorTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a852';
    private const string OLDEST_WAIT = '2027-03-05 07:00:00 UTC';

    #[Test]
    public function aCursorAfterAGroupHoldsItsOldestWaitAndItsPersonOrLabel(): void
    {
        $omarsGroup = new TurnGroup(new UserId(self::OMAR), null, 3, new \DateTimeImmutable(self::OLDEST_WAIT), []);
        $customersGroup = new TurnGroup(null, 'Customer', 2, new \DateTimeImmutable('2027-03-01 06:00:00 UTC'), []);

        $afterOmar = TurnGroupCursor::after($omarsGroup);
        $afterCustomer = TurnGroupCursor::after($customersGroup);

        self::assertSame(
            [[self::OLDEST_WAIT, self::OMAR, null], ['2027-03-01 06:00:00 UTC', null, 'Customer']],
            [
                [$afterOmar->oldestWaitingSince->format('Y-m-d H:i:s T'), $afterOmar->turnUserId?->toString(), $afterOmar->turnLabel],
                [$afterCustomer->oldestWaitingSince->format('Y-m-d H:i:s T'), $afterCustomer->turnUserId?->toString(), $afterCustomer->turnLabel],
            ],
        );
    }

    /**
     * @return iterable<string, array{UserId|null, string|null}>
     */
    public static function neitherOrBoth(): iterable
    {
        yield 'neither a person nor a label' => [null, null];
        yield 'both a person and a label' => [new UserId(self::OMAR), 'Customer'];
    }

    #[Test]
    #[DataProvider('neitherOrBoth')]
    public function aCursorAfterNeitherOrBothAPersonAndALabelIsRefused(?UserId $turnUserId, ?string $turnLabel): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A whose-turn cursor is after either a person\'s group or a label\'s.'));

        new TurnGroupCursor(new \DateTimeImmutable(self::OLDEST_WAIT), $turnUserId, $turnLabel);
    }
}
