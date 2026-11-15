<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\UI;

use App\Conversation\Domain\Message;
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
        yield 'the ticket page the thread page links back to' => [ThreadController::TICKET_ROUTE, TicketShowController::ROUTE];
        yield 'the not-found text for a ticket nobody may see' => [ThreadController::NOT_FOUND, TicketShowController::NOT_FOUND];
    }

    #[Test]
    #[DataProvider('copiesOfTicketShowControllersConstants')]
    public function aThreadActionUsesTheTicketPagesOwnValue(string $copy, string $original): void
    {
        self::assertSame($original, $copy);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function copiesOfTheDomainsLimits(): iterable
    {
        yield 'the most characters a message body may have' => [ThreadController::BODY_MAX_LENGTH, Message::MAX_BODY_LENGTH];
    }

    /**
     * Through a data provider: PHPStan reads two constants compared inline as always the same.
     */
    #[Test]
    #[DataProvider('copiesOfTheDomainsLimits')]
    public function theFormHintsTheLimitTheDomainEnforces(int $copy, int $original): void
    {
        self::assertSame($original, $copy);
    }
}
