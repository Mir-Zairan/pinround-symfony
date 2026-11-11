<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Infrastructure\Doctrine\TicketOpenTicketCounts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Until P03 adds tickets, no project has an open ticket.
 */
final class TicketOpenTicketCountsTest extends TestCase
{
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000025d1';
    private const string APP_PROJECT = '0197a3c0-0000-7000-8000-0000000025d2';

    #[Test]
    public function everyGivenProjectHasNoOpenTicketYet(): void
    {
        self::assertSame(
            [self::SHOP_PROJECT => 0, self::APP_PROJECT => 0],
            new TicketOpenTicketCounts()->openTicketsIn(new ProjectId(self::SHOP_PROJECT), new ProjectId(self::APP_PROJECT)),
        );
    }

    #[Test]
    public function noProjectGivesNoCounts(): void
    {
        self::assertSame([], new TicketOpenTicketCounts()->openTicketsIn());
    }
}
