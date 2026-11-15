<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Domain;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketTurnSnapshotTest extends TestCase
{
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c0d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000c0c1';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c0b1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c0b3';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c0b2';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000c0b4';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000c0b5';
    private const string NOOR = '0197a3c0-0000-7000-8000-00000000c0b6';
    private const string BEN = '0197a3c0-0000-7000-8000-00000000c0b7';

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

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function matchedQuestionPauses(): iterable
    {
        yield 'a paused ticket' => [true, true];
        yield 'a ticket that waits for no answer' => [false, false];
    }

    #[Test]
    #[DataProvider('matchedQuestionPauses')]
    public function anAskedPersonAndAskerBothPresentOrBothAbsentMakeASnapshot(bool $hasAsked, bool $hasAsker): void
    {
        $snapshot = self::pausedSnapshot($hasAsked, $hasAsker);

        self::assertSame(
            [$hasAsked ? self::LENA : null, $hasAsker ? self::MIA : null],
            [$snapshot->askedUserId?->toString(), $snapshot->askerId?->toString()],
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function mismatchedQuestionPauses(): iterable
    {
        yield 'an asked person with no asker' => [true, false];
        yield 'an asker with no asked person' => [false, true];
    }

    #[Test]
    #[DataProvider('mismatchedQuestionPauses')]
    public function anAskedPersonWithoutAnAskerOrTheOtherWayRoundIsRefused(bool $hasAsked, bool $hasAsker): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A paused ticket has both the asked person and the asker.');

        self::pausedSnapshot($hasAsked, $hasAsker);
    }

    /**
     * The team's other developers and testers, a pending assignee and whoever the ticket waits on are not on it.
     */
    #[Test]
    public function thePeopleOnTheTicketAreItsAssigneeReporterReviewerTesterAndLeadInThatOrder(): void
    {
        $snapshot = self::staffedSnapshot(assigneeId: self::OMAR, reviewerId: self::RAJ, testerId: self::SARA, teamId: self::SHOP_TEAM, leadId: self::LENA);

        self::assertSame([self::OMAR, self::MIA, self::RAJ, self::SARA, self::LENA], self::idsOf($snapshot->peopleOnTheTicket()));
    }

    #[Test]
    public function aPersonInTwoRolesIsOnTheTicketOnceAtTheFirstRole(): void
    {
        $snapshot = self::staffedSnapshot(assigneeId: self::LENA, reviewerId: self::MIA, testerId: null, teamId: self::SHOP_TEAM, leadId: self::LENA);

        self::assertSame([self::LENA, self::MIA], self::idsOf($snapshot->peopleOnTheTicket()));
    }

    #[Test]
    public function aTicketNoTeamOwnsAndNobodyWorksOnHasOnlyItsReporterOnIt(): void
    {
        $snapshot = self::staffedSnapshot(assigneeId: null, reviewerId: null, testerId: null, teamId: null, leadId: null);

        self::assertSame([self::MIA], self::idsOf($snapshot->peopleOnTheTicket()));
    }

    /**
     * @param list<UserId> $userIds
     *
     * @return list<string>
     */
    private static function idsOf(array $userIds): array
    {
        return array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
    }

    private static function staffedSnapshot(?string $assigneeId, ?string $reviewerId, ?string $testerId, ?string $teamId, ?string $leadId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-00000000c0e1',
            '0197a3c0-0000-7000-8000-00000000c0f1',
            'review',
            'check',
            null === $teamId ? null : new TeamId($teamId),
            null === $leadId ? null : new UserId($leadId),
            null === $leadId ? null : 'Lena',
            [new UserId(self::NOOR)],
            [new UserId(self::BEN)],
            'all',
            'major',
            null,
            new UserId(self::MIA),
            assigneeId: null === $assigneeId ? null : new UserId($assigneeId),
            pendingAssigneeId: new UserId(self::NOOR),
            reviewerId: null === $reviewerId ? null : new UserId($reviewerId),
            testerId: null === $testerId ? null : new UserId($testerId),
            waitingOnUserId: new UserId(self::BEN),
            waitingOnLabel: 'Ben',
        );
    }

    private static function pausedSnapshot(bool $hasAsked, bool $hasAsker): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-00000000c0e1',
            '0197a3c0-0000-7000-8000-00000000c0f1',
            'progress',
            'doing',
            new TeamId(self::SHOP_TEAM),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            'all',
            'major',
            null,
            new UserId(self::MIA),
            askedUserId: $hasAsked ? new UserId(self::LENA) : null,
            askerId: $hasAsker ? new UserId(self::MIA) : null,
        );
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
