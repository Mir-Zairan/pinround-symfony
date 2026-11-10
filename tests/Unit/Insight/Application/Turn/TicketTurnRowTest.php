<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\Turn;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnRow;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketTurnRowTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-0000000003a1';

    #[Test]
    public function aRowNamesAPersonOrALabel(): void
    {
        $byPerson = new TicketTurnRow($this->facts(), new UserId(self::LENA), null, NeedsYouKind::Build, 18, null);
        $byLabel = new TicketTurnRow($this->facts(), null, 'Customer', NeedsYouKind::Input, 20, null);

        self::assertSame([self::LENA, 'Customer'], [$byPerson->turnUserId?->toString(), $byLabel->turnLabel]);
    }

    /**
     * @return iterable<string, array{?UserId, ?string}>
     */
    public static function neitherOrBoth(): iterable
    {
        yield 'neither' => [null, null];
        yield 'both' => [new UserId(self::LENA), 'Customer'];
    }

    #[Test]
    #[DataProvider('neitherOrBoth')]
    public function aRowWithNeitherOrBothIsRefusedAsTheTableWould(?UserId $turnUserId, ?string $turnLabel): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A turn row names a person or a label, never both and never neither.');

        new TicketTurnRow($this->facts(), $turnUserId, $turnLabel, NeedsYouKind::Input, 20, null);
    }

    private function facts(): TicketTurnFacts
    {
        return new TicketTurnFacts(
            new TicketId('0190a000-0000-7000-8000-0000000003d1'),
            '0190a000-0000-7000-8000-0000000003c1',
            new TicketState('step', 'input', 'wait', null, reporterId: new UserId(self::LENA)),
            new TeamFacts(new UserId(self::LENA), 'Lena'),
            'all',
        );
    }
}
