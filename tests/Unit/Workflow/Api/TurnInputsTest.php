<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Api;

use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TurnInputs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnInputsTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c753';
    private const string NOOR = '0197a3c0-0000-7000-8000-00000000c754';
    private const string TEAM = '0197a3c0-0000-7000-8000-00000000c711';

    #[Test]
    public function theTicketStateCarriesEveryValueItWasGiven(): void
    {
        $state = TurnInputs::ticketState(
            '0197a3c0-0000-7000-8000-00000000c7a1',
            'review',
            'check',
            new TeamId(self::TEAM),
            new UserId(self::OMAR),
            new UserId(self::LENA),
            new UserId(self::MIA),
            new UserId(self::NOOR),
            '0197a3c0-0000-7000-8000-00000000c7a2',
            new UserId(self::LENA),
            new UserId(self::MIA),
            'Supplier',
            'major',
            '2.4',
        );

        self::assertSame(
            ['0197a3c0-0000-7000-8000-00000000c7a1', 'review', 'check', self::TEAM, self::OMAR, self::LENA, self::MIA, self::NOOR, '0197a3c0-0000-7000-8000-00000000c7a2', self::LENA, self::MIA, 'Supplier', 'major', '2.4'],
            [
                $state->stepId, $state->coreCode, $state->groupCode, $state->teamId?->toString(), $state->assigneeId?->toString(),
                $state->reviewerId?->toString(), $state->testerId?->toString(), $state->pendingAssigneeId?->toString(), $state->previousStepId,
                $state->reporterId?->toString(), $state->waitingOnUserId?->toString(), $state->waitingOnLabel, $state->priority, $state->release,
            ],
        );
    }

    #[Test]
    public function aTicketNoTeamOwnsKeepsNullsAndNoCoreCode(): void
    {
        $state = TurnInputs::ticketState('0197a3c0-0000-7000-8000-00000000c7a1', null, 'doing', null, null, null, null, null, null, null, null, null, null, null);

        self::assertSame([null, null, 'doing'], [$state->coreCode, $state->teamId, $state->groupCode]);
    }

    #[Test]
    public function eachDeveloperCountsItsReviewsAndEachTesterItsTestsAndNobodyCountsTheOtherKind(): void
    {
        $team = TurnInputs::teamFacts(
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::LENA), new UserId(self::OMAR)],
            [new UserId(self::MIA)],
            [self::OMAR => ['review' => 2, 'test' => 5], self::MIA => ['test' => 1, 'review' => 7], self::NOOR => ['review' => 9]],
        );

        self::assertSame(['Lena', self::LENA], [$team->leadDisplayName, $team->leadId->toString()]);
        self::assertSame(
            [[self::LENA, 0, 0], [self::OMAR, 2, 0]],
            array_map(static fn ($load): array => [$load->userId->toString(), $load->openReviews, $load->ticketsInTesting], $team->developers),
        );
        self::assertSame(
            [[self::MIA, 0, 1]],
            array_map(static fn ($load): array => [$load->userId->toString(), $load->openReviews, $load->ticketsInTesting], $team->testers),
        );
    }

    /**
     * The kinds are keyed by the values of Insight's constants, as TeamLoadQueries returns them.
     */
    #[Test]
    public function theLoadsInsightCountsByItsOwnKindNamesAreTheOnesTheTeamFactsCarry(): void
    {
        $team = TurnInputs::teamFacts(
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR)],
            [new UserId(self::MIA)],
            [self::OMAR => [TeamLoadQueries::KIND_REVIEW => 3], self::MIA => [TeamLoadQueries::KIND_TEST => 4]],
        );

        self::assertSame([3, 4], [$team->developers[0]->openReviews, $team->testers[0]->ticketsInTesting]);
    }

    #[Test]
    public function aTeamWithNoPeopleHasNoLoads(): void
    {
        $team = TurnInputs::teamFacts(new UserId(self::LENA), 'Lena', [], [], []);

        self::assertSame([[], []], [$team->developers, $team->testers]);
    }
}
