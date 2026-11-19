<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\UnknownApiTokenScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiTokenScopeTest extends TestCase
{
    #[Test]
    public function theScopesAreExactlyTheFiveOfAdr0021AndAdr0039(): void
    {
        self::assertSame(
            ['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write'],
            self::valuesOf(ApiTokenScope::cases()),
        );
    }

    #[Test]
    public function claudeCodeStartsWithReadAndRoundsWriteOnly(): void
    {
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], ApiTokenScope::claudeCodeDefaults());
    }

    #[Test]
    public function knownValuesBecomeScopesInTheOrderGiven(): void
    {
        self::assertSame([ApiTokenScope::TicketsMove, ApiTokenScope::Read], ApiTokenScope::fromValues(['tickets:move', 'read']));
        self::assertSame([], ApiTokenScope::fromValues([]));
    }

    #[Test]
    public function anUnknownValueIsRefusedEvenNextToKnownOnes(): void
    {
        $this->expectException(UnknownApiTokenScope::class);
        $this->expectExceptionMessageIsOrContains('A token scope must be one of: read, rounds:write, comments:write, tickets:move, reports:write.');

        ApiTokenScope::fromValues(['read', 'admin']);
    }

    #[Test]
    public function aScopeInAnotherLetterCaseIsUnknown(): void
    {
        $this->expectException(UnknownApiTokenScope::class);

        ApiTokenScope::fromValues(['READ']);
    }

    /**
     * Widened to any backed enum, so PHPStan does not read the cases' values as already known and call the check true.
     *
     * @param list<\BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private static function valuesOf(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
