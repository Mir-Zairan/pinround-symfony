<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Insight\Api\TurnGroupCursor;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\UI\WhoseTurnCursorParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhoseTurnCursorParameterTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a852';
    /** 5 Mar 2027 07:00 UTC. */
    private const int OLDEST_WAIT_SECONDS = 1804230000;

    /**
     * @return iterable<string, array{TurnGroupCursor, string}>
     */
    public static function cursors(): iterable
    {
        $oldestWaitingSince = new \DateTimeImmutable('@'.self::OLDEST_WAIT_SECONDS);

        yield 'after a person' => [new TurnGroupCursor($oldestWaitingSince, new UserId(self::OMAR), null), self::OLDEST_WAIT_SECONDS.'.person.'.self::OMAR];
        yield 'after a label' => [new TurnGroupCursor($oldestWaitingSince, null, 'Customer'), self::OLDEST_WAIT_SECONDS.'.label.Customer'];
        yield 'after a label with dots, a line break and multibyte text' => [new TurnGroupCursor($oldestWaitingSince, null, "v1.2.person.\nKüche"), self::OLDEST_WAIT_SECONDS.".label.v1.2.person.\nKüche"];
    }

    #[Test]
    #[DataProvider('cursors')]
    public function aCursorGoesToItsValueAndBackUnchanged(TurnGroupCursor $cursor, string $expectedValue): void
    {
        $value = WhoseTurnCursorParameter::valueOf($cursor);
        $readBack = WhoseTurnCursorParameter::cursorOf($value);

        self::assertSame($expectedValue, $value);
        self::assertNotNull($readBack);
        self::assertSame(
            [self::OLDEST_WAIT_SECONDS, $cursor->turnUserId?->toString(), $cursor->turnLabel],
            [$readBack->oldestWaitingSince->getTimestamp(), $readBack->turnUserId?->toString(), $readBack->turnLabel],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'no wait' => ['person.'.self::OMAR];
        yield 'a wait that is not a number' => ['yesterday.label.Customer'];
        yield 'a kind of no group' => [self::OLDEST_WAIT_SECONDS.'.team.'.self::OMAR];
        yield 'an empty label' => [self::OLDEST_WAIT_SECONDS.'.label.'];
        yield 'a person by an upper-case id' => [self::OLDEST_WAIT_SECONDS.'.person.'.strtoupper(self::OMAR)];
        yield 'a person by a v4 uuid' => [self::OLDEST_WAIT_SECONDS.'.person.4f6b1c9e-8d2a-4b7e-9c1d-2e3f4a5b6c7d'];
        yield 'a person id with a trailing newline' => [self::OLDEST_WAIT_SECONDS.'.person.'.self::OMAR."\n"];
    }

    #[Test]
    #[DataProvider('malformedValues')]
    public function aValueValueOfNeverMakesIsNoCursor(string $value): void
    {
        self::assertNull(WhoseTurnCursorParameter::cursorOf($value));
    }
}
