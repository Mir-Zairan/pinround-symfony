<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\Turn;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketTurnFactsTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-0000000002a1';
    private const string MIA = '0190a000-0000-7000-8000-0000000002a3';

    #[Test]
    public function theReporterIsTheTicketsAndBothVisibilitiesAreAccepted(): void
    {
        $restricted = $this->facts(new UserId(self::MIA), 'team');

        self::assertSame(self::MIA, $restricted->reporterId()->toString());
        self::assertSame('all', $this->facts(new UserId(self::MIA), 'all')->visibility);
    }

    #[Test]
    public function factsWithoutAReporterAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The turn facts of a ticket need its reporter.');

        $this->facts(null, 'all');
    }

    #[Test]
    public function aVisibilityOtherThanAllOrTeamIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ticket visibility must be all or team.');

        $this->facts(new UserId(self::MIA), 'private');
    }

    private function facts(?UserId $reporterId, string $visibility): TicketTurnFacts
    {
        return new TicketTurnFacts(
            new TicketId('0190a000-0000-7000-8000-0000000002d1'),
            '0190a000-0000-7000-8000-0000000002c1',
            new TicketState('step', 'todo', 'todo', null, reporterId: $reporterId),
            new TeamFacts(new UserId(self::LENA), 'Lena'),
            $visibility,
        );
    }
}
