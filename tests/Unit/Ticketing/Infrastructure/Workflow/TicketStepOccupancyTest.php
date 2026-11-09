<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Infrastructure\Workflow;

use App\Ticketing\Infrastructure\Workflow\TicketStepOccupancy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Until tickets exist (P03) no step holds an open ticket, so every company and team step may be removed.
 */
final class TicketStepOccupancyTest extends TestCase
{
    #[Test]
    public function noStepHoldsAnOpenTicketBeforeTicketsExist(): void
    {
        self::assertSame(0, new TicketStepOccupancy()->openTicketsIn('0197a3c0-0000-7000-8000-000000001ed1'));
    }
}
