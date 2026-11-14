<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\UI;

use App\Conversation\UI\ThreadController;
use App\Ticketing\UI\TicketShowController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreadControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function copiesOfTicketShowControllersConstants(): iterable
    {
        yield 'the route a thread action redirects to' => [ThreadController::TICKET_ROUTE, TicketShowController::ROUTE];
        yield 'the not-found text for a ticket nobody may see' => [ThreadController::NOT_FOUND, TicketShowController::NOT_FOUND];
    }

    #[Test]
    #[DataProvider('copiesOfTicketShowControllersConstants')]
    public function aThreadActionUsesTheTicketPagesOwnValue(string $copy, string $original): void
    {
        self::assertSame($original, $copy);
    }
}
