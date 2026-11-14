<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Api;

use App\Insight\Api\TurnGroup;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnGroupTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a852';

    #[Test]
    public function aPersonsGroupIsNamedByTheUserIdAndALabelsByTheLabel(): void
    {
        self::assertSame(
            [self::OMAR, 'Customer'],
            [self::groupOf(new UserId(self::OMAR), null)->groupKey(), self::groupOf(null, 'Customer')->groupKey()],
        );
    }

    #[Test]
    public function aGroupOfBothAPersonAndALabelIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A whose-turn group is either a person\'s or a label\'s.'));

        self::groupOf(new UserId(self::OMAR), 'Customer');
    }

    #[Test]
    public function aGroupOfNeitherAPersonNorALabelIsRefused(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A whose-turn group is either a person\'s or a label\'s.'));

        self::groupOf(null, null);
    }

    private static function groupOf(?UserId $turnUserId, ?string $turnLabel): TurnGroup
    {
        return new TurnGroup($turnUserId, $turnLabel, 1, new \DateTimeImmutable('2027-03-05 07:00:00 UTC'), []);
    }
}
