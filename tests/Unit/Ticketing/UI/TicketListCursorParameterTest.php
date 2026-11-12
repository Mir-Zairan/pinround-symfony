<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\UI\TicketListCursorParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketListCursorParameterTest extends TestCase
{
    private const string STEP = '0190a000-0000-7000-8000-0000000000c1';
    private const string TICKET = '0190a000-0000-7000-8000-0000000000d1';

    #[Test]
    public function aCursorComesBackFromTheQueryItIsSentAs(): void
    {
        $cursor = new Cursor(self::STEP, 3, new \DateTimeImmutable('2026-03-15T10:30:00+00:00'), new TicketId(self::TICKET));

        $query = TicketListCursorParameter::queryOf($cursor);
        $readBack = TicketListCursorParameter::cursorOf($query[TicketListCursorParameter::STEP_QUERY], $query[TicketListCursorParameter::AFTER_QUERY]);

        self::assertSame([TicketListCursorParameter::STEP_QUERY => self::STEP, TicketListCursorParameter::AFTER_QUERY => '3.1773570600.'.self::TICKET], $query);
        self::assertNotNull($readBack);
        self::assertSame(self::STEP, $readBack->stepId);
        self::assertSame(3, $readBack->priorityRank);
        self::assertSame(1773570600, $readBack->updatedAt->getTimestamp());
        self::assertSame(self::TICKET, $readBack->ticketId->toString());
        self::assertNull($readBack->matchPosition);
    }

    #[Test]
    public function aSearchCursorComesBackWithItsMatchPosition(): void
    {
        $cursor = new Cursor(self::STEP, 3, new \DateTimeImmutable('2026-03-15T10:30:00+00:00'), new TicketId(self::TICKET), Cursor::matchPositionOf(true, '0.169808963686'));

        $query = TicketListCursorParameter::queryOf($cursor);
        $readBack = TicketListCursorParameter::cursorOf($query[TicketListCursorParameter::STEP_QUERY], $query[TicketListCursorParameter::AFTER_QUERY]);

        self::assertSame('3.1773570600.'.self::TICKET.'.1_0.169808963686', $query[TicketListCursorParameter::AFTER_QUERY]);
        self::assertSame([[true, '0.169808963686'], self::TICKET], [$readBack?->searchOrderValues(), $readBack?->ticketId->toString()]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badParameters(): iterable
    {
        $after = '3.1773570600.'.self::TICKET;
        yield 'empty step' => ['', $after];
        yield 'step not a uuid' => ['to-do', $after];
        yield 'step in capitals' => [strtoupper(self::STEP), $after];
        yield 'empty after' => [self::STEP, ''];
        yield 'rank above 4' => [self::STEP, '5.1773570600.'.self::TICKET];
        yield 'negative time' => [self::STEP, '3.-1.'.self::TICKET];
        yield 'time too long' => [self::STEP, '3.123456789012.'.self::TICKET];
        yield 'ticket id not v7' => [self::STEP, '3.1773570600.0190a000-0000-4000-8000-0000000000d1'];
        yield 'ticket id in capitals' => [self::STEP, '3.1773570600.'.strtoupper(self::TICKET)];
        yield 'a part missing' => [self::STEP, '3.'.self::TICKET];
        yield 'trailing newline' => [self::STEP, $after."\n"];
        yield 'an old ordinal match position' => [self::STEP, $after.'.7'];
        yield 'match position without its flag' => [self::STEP, $after.'.0.169808963686'];
        yield 'match position with a flag of 2' => [self::STEP, $after.'.2_0.169808963686'];
        yield 'match score with an exponent' => [self::STEP, $after.'.0_1.69808963686e-01'];
        yield 'match score of 13 decimals' => [self::STEP, $after.'.0_0.1698089636861'];
        yield 'match score negative' => [self::STEP, $after.'.0_-0.169808963686'];
        yield 'match position empty' => [self::STEP, $after.'.'];
        yield 'match position then a newline' => [self::STEP, $after.".1_0.169808963686\n"];
    }

    #[Test]
    #[DataProvider('badParameters')]
    public function aPairNotMadeByQueryOfIsNoCursor(string $stepId, string $after): void
    {
        self::assertNull(TicketListCursorParameter::cursorOf($stepId, $after));
    }
}
