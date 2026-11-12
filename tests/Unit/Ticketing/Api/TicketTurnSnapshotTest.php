<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketTurnSnapshotTest extends TestCase
{
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c0d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000c0c1';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c0b1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c0b3';

    /**
     * @return iterable<string, array{bool, bool, bool, string}>
     */
    public static function validSnapshots(): iterable
    {
        yield 'a team ticket for everyone' => [true, true, true, 'all'];
        yield 'a restricted team ticket' => [true, true, true, 'team'];
        yield 'a ticket no team owns' => [false, false, false, 'all'];
    }

    #[Test]
    #[DataProvider('validSnapshots')]
    public function aTeamLeadAndNameAllPresentOrAllAbsentWithAKnownVisibilityMakeASnapshot(bool $hasTeam, bool $hasLead, bool $hasLeadName, string $visibility): void
    {
        $snapshot = self::snapshot($hasTeam, $hasLead, $hasLeadName, $visibility);

        self::assertSame(
            [$hasTeam ? self::SHOP_TEAM : null, $hasLead ? self::LENA : null, $hasLeadName ? 'Lena' : null, $visibility],
            [$snapshot->teamId?->toString(), $snapshot->leadId?->toString(), $snapshot->leadDisplayName, $snapshot->visibility],
        );
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function mismatchedTeamAndLead(): iterable
    {
        yield 'a team with no lead and no name' => [true, false, false];
        yield 'a team and a lead with no name' => [true, true, false];
        yield 'a team and a name with no lead' => [true, false, true];
        yield 'a lead and a name with no team' => [false, true, true];
        yield 'a lead alone' => [false, true, false];
        yield 'a name alone' => [false, false, true];
    }

    #[Test]
    #[DataProvider('mismatchedTeamAndLead')]
    public function aTeamLeadAndNameNotAllPresentOrAllAbsentAreRefused(bool $hasTeam, bool $hasLead, bool $hasLeadName): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ticket has a team lead and their name exactly when it has a team.');

        self::snapshot($hasTeam, $hasLead, $hasLeadName, 'all');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownVisibilities(): iterable
    {
        yield 'empty' => [''];
        yield 'another word' => ['private'];
        yield 'another case' => ['Team'];
    }

    #[Test]
    #[DataProvider('unknownVisibilities')]
    public function aVisibilityOtherThanAllOrTeamIsRefused(string $visibility): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ticket visibility must be all or team.');

        self::snapshot(true, true, true, $visibility);
    }

    private static function snapshot(bool $hasTeam, bool $hasLead, bool $hasLeadName, string $visibility): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-00000000c0e1',
            '0197a3c0-0000-7000-8000-00000000c0f1',
            'todo',
            'todo',
            $hasTeam ? new TeamId(self::SHOP_TEAM) : null,
            $hasLead ? new UserId(self::LENA) : null,
            $hasLeadName ? 'Lena' : null,
            [],
            [],
            $visibility,
            'major',
            null,
            new UserId(self::MIA),
        );
    }
}
