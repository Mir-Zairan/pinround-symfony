<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\Api\TicketPosition;
use App\Ticketing\UI\TicketShowController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "N of M" line, which the ticket page builds itself.
 */
final class TicketShowControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{TicketPosition, string}>
     */
    public static function positions(): iterable
    {
        yield 'whole counts' => [new TicketPosition(3, 61, 'SHOP-2', 'SHOP-4', false), '3 of 61'];
        yield 'a total past a step\'s cap' => [new TicketPosition(3, 1000, 'SHOP-2', 'SHOP-4', true), '3 of 1,000+'];
        yield 'a place past a step\'s cap' => [new TicketPosition(1003, 2000, 'SHOP-2', 'SHOP-4', true, true), '1,003+ of 2,000+'];
    }

    #[Test]
    #[DataProvider('positions')]
    public function aPositionReadsAsTheListPrintsItsCounts(TicketPosition $position, string $expectedLine): void
    {
        self::assertSame($expectedLine, TicketShowController::positionLineOf($position));
    }
}
