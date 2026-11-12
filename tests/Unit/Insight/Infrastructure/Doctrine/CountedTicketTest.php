<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Infrastructure\Doctrine;

use App\Insight\Infrastructure\Doctrine\CountedTicket;
use App\SharedKernel\Domain\TeamId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CountedTicketTest extends TestCase
{
    private const string FIRST_PROJECT = '0197a3c0-0000-7000-8000-00000000a001';
    private const string SECOND_PROJECT = '0197a3c0-0000-7000-8000-00000000a002';
    private const string FIRST_TEAM = '0197a3c0-0000-7000-8000-00000000b001';
    private const string SECOND_TEAM = '0197a3c0-0000-7000-8000-00000000b002';

    /**
     * Each key decides only when the ones before it tie: a later team or visibility never puts a row of the first
     * project after one of the second.
     */
    #[Test]
    public function countRowsCompareByProjectThenTeamThenVisibility(): void
    {
        $rowsInKeyOrder = [
            self::counted(self::FIRST_PROJECT, self::SECOND_TEAM, 'team'),
            self::counted(self::SECOND_PROJECT, self::FIRST_TEAM, 'team'),
            self::counted(self::SECOND_PROJECT, self::SECOND_TEAM, 'all'),
            self::counted(self::SECOND_PROJECT, self::SECOND_TEAM, 'team'),
        ];
        $shuffled = [$rowsInKeyOrder[3], $rowsInKeyOrder[1], $rowsInKeyOrder[0], $rowsInKeyOrder[2]];

        usort($shuffled, static fn (CountedTicket $first, CountedTicket $second): int => $first->compareCountRow($second));

        self::assertSame($rowsInKeyOrder, $shuffled);
    }

    #[Test]
    public function doneAndOpenInOneRowCompareEqualAndUpperCaseIdsAsLowerCase(): void
    {
        $open = self::counted(self::FIRST_PROJECT, self::FIRST_TEAM, 'all');
        $done = new CountedTicket(strtoupper(self::FIRST_PROJECT), new TeamId(self::FIRST_TEAM), 'all', true);

        self::assertSame(0, $open->compareCountRow($done));
        self::assertSame(0, $done->compareCountRow($open));
        self::assertLessThan(0, $done->compareCountRow(self::counted(self::SECOND_PROJECT, self::FIRST_TEAM, 'all')));
    }

    private static function counted(string $projectId, string $teamId, string $visibility): CountedTicket
    {
        return new CountedTicket($projectId, new TeamId($teamId), $visibility, false);
    }
}
