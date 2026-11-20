<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\MyReports;

use App\Insight\Api\MyReportsCursor;
use App\SharedKernel\Domain\TicketId;
use App\Web\UI\MyReports\MyReportsCursorParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MyReportsCursorParameterTest extends TestCase
{
    private const string TICKET = '0199a000-0000-7000-8000-00000000f001';

    #[Test]
    public function aCursorComesBackFromItsValueToTheSecond(): void
    {
        $cursor = new MyReportsCursor(new \DateTimeImmutable('2026-09-01 11:00:00+02:00'), new TicketId(self::TICKET));

        $value = MyReportsCursorParameter::valueOf($cursor);
        $parsed = MyReportsCursorParameter::cursorOf($value);

        self::assertSame('1788253200.'.self::TICKET, $value);
        self::assertNotNull($parsed);
        self::assertSame(1788253200, $parsed->reportedAt->getTimestamp());
        self::assertTrue($parsed->ticketId->equals(new TicketId(self::TICKET)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'empty' => [''];
        yield 'no separator' => ['1788253200'];
        yield 'no time' => ['.'.self::TICKET];
        yield 'a negative time' => ['-1.'.self::TICKET];
        yield 'a time of twelve digits' => ['178825320000.'.self::TICKET];
        yield 'an id that is no uuid' => ['1788253200.SHOP-1'];
        yield 'an uppercase id' => ['1788253200.'.strtoupper(self::TICKET)];
        yield 'a uuid that is not version 7' => ['1788253200.0199a000-0000-4000-8000-00000000f001'];
        yield 'a newline after the id' => ['1788253200.'.self::TICKET."\n"];
    }

    #[Test]
    #[DataProvider('malformedValues')]
    public function aValueThatValueOfDoesNotMakeIsNoCursor(string $value): void
    {
        self::assertNull(MyReportsCursorParameter::cursorOf($value));
    }
}
