<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\DraftDecision;
use App\Review\Domain\DraftHasNoScreenshots;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\RetakenScreenshotNotFound;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotTriesFull;
use App\Review\Domain\ScreenshotVerdict;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000000e01';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string NEW_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c99';
    private const string UNKNOWN_SCREENSHOT = '0197a3c0-0000-7000-8000-000000000c77';

    #[Test]
    public function aReportIsRoundZeroSharedAtTheMomentItWasReportedAndOpen(): void
    {
        $reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');

        $round = Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [self::screenshot(0)], $reportedAt);

        self::assertSame(RoundKind::Report, $round->kind());
        self::assertSame(0, $round->number());
        self::assertSame(Round::REPORT_NUMBER, $round->number());
        self::assertSame($reportedAt, $round->sharedAt());
        self::assertSame($reportedAt, $round->createdAt());
        self::assertSame(RoundState::Open, $round->state());
        self::assertSame(1, $round->version());
        self::assertFalse($round->isUnsharedDraft());
        self::assertSame(self::LENA, $round->ownerId()->toString());
        self::assertSame(self::ROUND, $round->id()->toString());
        self::assertSame(self::ACME_FOODS, $round->organizationId()->toString());
        self::assertSame(self::TICKET, $round->ticketId()->toString());
    }

    #[Test]
    public function aReportNeedsAtLeastOneScreenshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A report round needs at least one screenshot.');

        self::report([]);
    }

    #[Test]
    public function aReportHoldsExactlyTheMaximumNumberOfScreenshotsAndRefusesOneMore(): void
    {
        $screenshots = [];
        for ($position = 0; $position < Round::MAX_SCREENSHOTS; ++$position) {
            $screenshots[] = self::screenshot($position);
        }
        self::assertCount(50, self::report($screenshots)->screenshots());

        $screenshots[] = self::screenshot(Round::MAX_SCREENSHOTS);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A round holds at most 50 screenshots.');

        self::report($screenshots);
    }

    #[Test]
    public function aReportRefusesTwoScreenshotsAtOnePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Two screenshots of one round cannot share a position.');

        self::report([self::screenshot(0), self::screenshot(1), self::screenshot(1)]);
    }

    #[Test]
    public function aReportKeepsItsScreenshotsInPositionOrder(): void
    {
        $round = self::report([self::screenshot(2), self::screenshot(0), self::screenshot(1)]);

        self::assertSame([0, 1, 2], self::positionsOf($round));
    }

    #[Test]
    public function aDraftIsAnEmptyUnsharedRoundWithNoNumber(): void
    {
        $startedAt = new \DateTimeImmutable('2026-03-04 10:00:00+00:00');

        $draft = Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), $startedAt);

        self::assertSame(RoundKind::Draft, $draft->kind());
        self::assertNull($draft->number());
        self::assertNull($draft->sharedAt());
        self::assertTrue($draft->isUnsharedDraft());
        self::assertSame([], $draft->screenshots());
        self::assertSame(RoundState::Open, $draft->state());
        self::assertSame(1, $draft->version());
        self::assertSame($startedAt, $draft->createdAt());
        self::assertSame(self::OMAR, $draft->ownerId()->toString());
        self::assertNull($draft->deletedAt());
        self::assertNull($draft->decision());
        self::assertNull($draft->decidedAt());
    }

    #[Test]
    public function theOwnerSharesADraftAsTheGivenNumberAndTheVersionGoesUp(): void
    {
        $draft = self::draftWithScreenshots(1);
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');

        $draft->share(new UserId(self::OMAR), 1, $sharedAt);

        self::assertSame(1, $draft->number());
        self::assertSame($sharedAt, $draft->sharedAt());
        self::assertFalse($draft->isUnsharedDraft());
        self::assertSame(RoundKind::Draft, $draft->kind());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    /**
     * Chain A is screenshots 0, 2 and 3 (try 3 last), B at 1 was never retaken, chain C is 4 and 5. Try 1 of A and of C
     * carry pins, which the team must not see, and A's try 3 carries its own. The earlier tries stay for the owner.
     */
    #[Test]
    public function aShareKeepsEveryTryWithItsPinsAndTheTeamSeesOnlyTheLatestTryOfEachScreenshot(): void
    {
        $draft = self::draftWithTries();
        $screenshotsBefore = self::snapshot($draft)['screenshots'];
        $triesBefore = self::triesOf($draft);

        $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));

        self::assertSame($screenshotsBefore, self::snapshot($draft)['screenshots'], 'Every try stays with its pins.');
        self::assertSame($triesBefore, self::triesOf($draft));
        self::assertSame([[0, 1, 3], [1, 1, 1], [0, 2, 3], [0, 3, 3], [4, 1, 2], [4, 2, 2]], self::triesOf($draft));
        self::assertSame(
            [[1, null, []], [3, self::screenshotId(0)->toString(), [2]], [5, self::screenshotId(4)->toString(), []]],
            array_map(static fn (Screenshot $screenshot): array => [
                $screenshot->position(),
                $screenshot->retakeOf()?->toString(),
                array_map(static fn (Pin $pin): int => $pin->number(), $screenshot->pins()),
            ], $draft->latestTries()),
            'The latest try of each screenshot, by position, with its own pins only.',
        );
        self::assertSame(2, $draft->version(), 'The share is one change.');
    }

    #[Test]
    public function theLatestTriesOfADraftWithNoRetakeAreAllItsScreenshots(): void
    {
        $draft = self::draftWithScreenshots(3);
        self::assertCount(3, $draft->screenshots());

        self::assertEquals($draft->screenshots(), $draft->latestTries());
    }

    #[Test]
    public function aDraftWithNoScreenshotHasNoLatestTry(): void
    {
        self::assertSame([], self::draft()->latestTries());
    }

    /**
     * Try 1 of chain A (position 0) is removed, so try 2 starts the chain again; try 3 is still its latest.
     */
    #[Test]
    public function afterAFirstTryIsRemovedTheLatestTryIsStillTheLastOfTheChain(): void
    {
        $draft = self::draftWithTries();

        $draft->removeScreenshot(new UserId(self::OMAR), self::screenshotId(0));

        self::assertSame(
            [self::screenshotId(1)->toString(), self::screenshotId(3)->toString(), self::screenshotId(5)->toString()],
            array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $draft->latestTries()),
        );
    }

    #[Test]
    public function aShareOfADraftWithNoRetakeKeepsEveryScreenshot(): void
    {
        $draft = self::draftWithScreenshots(3);
        $before = $draft->screenshots();
        self::assertCount(3, $before);

        $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));

        self::assertEquals($before, $draft->screenshots(), 'Every screenshot stays, with all it had.');
    }

    #[Test]
    public function aRefusedShareKeepsEveryTry(): void
    {
        $refusals = [
            [self::OMAR, 0, 'A shared draft round gets a number from 1; Round 0 is the report.'],
            [self::LENA, 2, 'Only the person who owns a draft round can share it.'],
        ];
        foreach ($refusals as [$sharerId, $number, $reason]) {
            $draft = self::draftWithTries();
            $before = self::snapshot($draft);
            self::assertCount(6, $draft->screenshots());

            try {
                $draft->share(new UserId($sharerId), $number, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
                self::fail('The share was accepted.');
            } catch (\DomainException $refused) {
                self::assertSame($reason, $refused->getMessage());
            }

            self::assertSame($before, self::snapshot($draft));
        }
    }

    #[Test]
    public function aDraftCannotBeSharedAsRoundZeroOrBelow(): void
    {
        foreach ([0, -1] as $number) {
            $draft = self::draftWithScreenshots(1);
            $before = self::snapshot($draft);

            try {
                $draft->share(new UserId(self::OMAR), $number, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
                self::fail('Sharing as number '.$number.' was allowed.');
            } catch (\DomainException $refused) {
                self::assertSame('A shared draft round gets a number from 1; Round 0 is the report.', $refused->getMessage());
            }
            self::assertSame($before, self::snapshot($draft));
            self::assertTrue($draft->isUnsharedDraft());
        }
    }

    #[Test]
    public function aDraftCannotBeSharedTwiceAndKeepsItsFirstNumber(): void
    {
        $draft = self::draftWithScreenshots(1);
        $firstSharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $draft->share(new UserId(self::OMAR), 1, $firstSharedAt);

        try {
            $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'));
            self::fail('A shared draft was shared again.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is shared already.', $refused->getMessage());
        }

        self::assertSame(1, $draft->number());
        self::assertSame($firstSharedAt, $draft->sharedAt());
        self::assertSame(2, $draft->version());
    }

    #[Test]
    public function onlyTheOwnerCanShareADraft(): void
    {
        $draft = self::draftWithScreenshots(1);
        $before = self::snapshot($draft);

        try {
            $draft->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('Another person shared the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can share it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertTrue($draft->isUnsharedDraft());
    }

    #[Test]
    public function aDeletedDraftCannotBeSharedAndStaysDeleted(): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-04 12:00:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('A deleted draft was shared.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aReportCannotBeSharedAndKeepsItsNumberAndTime(): void
    {
        $reportedAt = new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
        $report = Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [self::screenshot(0)], $reportedAt);

        try {
            $report->share(new UserId(self::LENA), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('A report round was shared.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round can be shared.', $refused->getMessage());
        }

        self::assertSame(0, $report->number());
        self::assertSame($reportedAt, $report->sharedAt());
        self::assertSame(1, $report->version());
    }

    #[Test]
    public function aDraftWithNoScreenshotCannotBeSharedAndStaysUnsharedAndUnnumbered(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
            self::fail('An empty draft was shared.');
        } catch (DraftHasNoScreenshots $refused) {
            self::assertSame('Add a screenshot before you share.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertTrue($draft->isUnsharedDraft());
        self::assertNull($draft->number());
        self::assertNull($draft->sharedAt());
    }

    #[Test]
    public function aDraftWhoseOnlyScreenshotWasRemovedCannotBeShared(): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->removeScreenshot(new UserId(self::OMAR), self::screenshotId(0));

        $this->expectException(DraftHasNoScreenshots::class);
        $this->expectExceptionMessageIsOrContains('Add a screenshot before you share.');

        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
    }

    #[Test]
    public function closingASharedDraftClosesItKeepsItsNumberAndTheVersionGoesUp(): void
    {
        $draft = self::draftWithScreenshots(1);
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $draft->share(new UserId(self::OMAR), 2, $sharedAt);
        self::assertTrue($draft->isOpenSharedRound());

        $draft->close();

        self::assertSame(RoundState::Closed, $draft->state());
        self::assertFalse($draft->isOpenSharedRound());
        self::assertSame([2, $sharedAt, 3, null], [$draft->number(), $draft->sharedAt(), $draft->version(), $draft->deletedAt()]);
        self::assertCount(1, $draft->screenshots());
    }

    #[Test]
    public function closingAnOpenReviewRoundClosesIt(): void
    {
        $review = self::storedRound(RoundKind::Review, self::OMAR, 3, RoundState::Open);

        $review->close();

        self::assertSame(RoundState::Closed, $review->state());
        self::assertSame(2, $review->version());
        self::assertSame(1, $review->savedVersion());
    }

    /**
     * @return iterable<string, array{\Closure(): Round, string}>
     */
    public static function roundsThatCannotBeClosed(): iterable
    {
        $notShared = 'Only a shared round is closed when the ticket moves on.';
        yield 'an unshared draft' => [static fn (): Round => self::draftWithScreenshots(1), $notShared];
        yield 'a deleted draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, null, RoundState::Closed, true), $notShared];
        yield 'Round 0' => [static fn (): Round => self::report([self::screenshot(0)]), $notShared];
        yield 'the checklist round' => [static fn (): Round => self::storedRound(RoundKind::Checklist, self::LENA, 1, RoundState::Open), $notShared];
        yield 'a closed shared draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, 2, RoundState::Closed), 'This round is closed already.'];
        yield 'a closed review round' => [static fn (): Round => self::storedRound(RoundKind::Review, self::OMAR, 3, RoundState::Closed), 'This round is closed already.'];
    }

    /**
     * @param \Closure(): Round $roundOf
     */
    #[Test]
    #[DataProvider('roundsThatCannotBeClosed')]
    public function aRoundThatIsNotAnOpenSharedRoundIsNotClosedAndStaysAsItWas(\Closure $roundOf, string $reason): void
    {
        $round = $roundOf();
        $before = self::snapshot($round);

        try {
            $round->close();
            self::fail('The round was closed.');
        } catch (\DomainException $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($round));
    }

    #[Test]
    public function aSharedDraftCannotBeClosedTwice(): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->share(new UserId(self::OMAR), 2, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $draft->close();
        $before = self::snapshot($draft);

        try {
            $draft->close();
            self::fail('A closed round was closed again.');
        } catch (\DomainException $refused) {
            self::assertSame('This round is closed already.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @return iterable<string, array{\Closure(): Round, bool}>
     */
    public static function roundsAndWhetherTheyAreOpenSharedRounds(): iterable
    {
        yield 'an unshared draft' => [static fn (): Round => self::draftWithScreenshots(1), false];
        yield 'a deleted draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, null, RoundState::Closed, true), false];
        yield 'an open shared draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, 2, RoundState::Open), true];
        yield 'a closed shared draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, 2, RoundState::Closed), false];
        yield 'an open review round' => [static fn (): Round => self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Open), true];
        yield 'a closed review round' => [static fn (): Round => self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Closed), false];
        yield 'Round 0, open' => [static fn (): Round => self::report([self::screenshot(0)]), false];
        yield 'the checklist round, open' => [static fn (): Round => self::storedRound(RoundKind::Checklist, self::LENA, 1, RoundState::Open), false];
    }

    /**
     * @param \Closure(): Round $roundOf
     */
    #[Test]
    #[DataProvider('roundsAndWhetherTheyAreOpenSharedRounds')]
    public function onlyAnOpenSharedDraftOrReviewRoundIsAnOpenSharedRound(\Closure $roundOf, bool $isOpenSharedRound): void
    {
        self::assertSame($isOpenSharedRound, $roundOf()->isOpenSharedRound());
    }

    #[Test]
    public function aSharedDraftOfAnyoneButTheAssigneeIsFindingsForTheAssignee(): void
    {
        $miasFindings = self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Open);
        $closedFindings = self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Closed);

        self::assertTrue($miasFindings->isFindingsFor(new UserId(self::OMAR)));
        self::assertTrue($closedFindings->isFindingsFor(new UserId(self::OMAR)));
        self::assertFalse($miasFindings->isFindingsFor(new UserId(self::MIA)), 'The assignee\'s own shared draft is a self-check, not findings.');
    }

    /**
     * @return iterable<string, array{\Closure(): Round}>
     */
    public static function roundsThatAreNeverFindings(): iterable
    {
        yield 'another person\'s unshared draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, null, RoundState::Open)];
        yield 'another person\'s deleted draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, null, RoundState::Closed, true)];
        yield 'another person\'s review round' => [static fn (): Round => self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Open)];
        yield 'another person\'s Round 0' => [static fn (): Round => self::report([self::screenshot(0)])];
        yield 'the checklist round' => [static fn (): Round => self::storedRound(RoundKind::Checklist, self::LENA, 1, RoundState::Open)];
    }

    /**
     * @param \Closure(): Round $roundOf
     */
    #[Test]
    #[DataProvider('roundsThatAreNeverFindings')]
    public function onlyASharedDraftIsFindings(\Closure $roundOf): void
    {
        self::assertFalse($roundOf()->isFindingsFor(new UserId(self::OMAR)));
    }

    /**
     * Omar is the assignee unless a case says nobody is; "Share now" is a share outside a move, which records no decision.
     *
     * @return iterable<string, array{\Closure(): Round, string, bool, string|null, bool}> the round, the mover, whether
     *                                                                                     it was their turn, the assignee,
     *                                                                                     and whether the move closes it
     */
    public static function movesAndWhetherTheyCloseTheRound(): iterable
    {
        $miasFindings = static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Open);
        $omarsSharedNow = static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, 3, RoundState::Open);
        $omarsSharedInAMove = static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, 3, RoundState::Open, false, DraftDecision::Shared);
        $lenasReview = static fn (): Round => self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Open);

        yield 'Lena moves on her turn: Mia\'s findings' => [$miasFindings, self::LENA, true, self::OMAR, true];
        yield 'Mia moves on her turn: Lena\'s review round' => [$lenasReview, self::MIA, true, self::OMAR, true];
        yield 'Mia moves on her turn: Omar\'s round shared now' => [$omarsSharedNow, self::MIA, true, self::OMAR, true];
        yield 'the assignee moves outside his turn: Mia\'s findings' => [$miasFindings, self::OMAR, false, self::OMAR, true];
        yield 'Omar moves on his turn: his round shared in an earlier move' => [$omarsSharedInAMove, self::OMAR, true, self::OMAR, true];

        yield 'Omar moves on his turn: his own round shared now' => [$omarsSharedNow, self::OMAR, true, self::OMAR, false];
        yield 'the assignee moves outside his turn: his own round shared now' => [$omarsSharedNow, self::OMAR, false, self::OMAR, false];
        yield 'the assignee moves outside his turn: his round shared in an earlier move, a self-check' => [$omarsSharedInAMove, self::OMAR, false, self::OMAR, false];
        yield 'the assignee moves outside his turn: Lena\'s review round' => [$lenasReview, self::OMAR, false, self::OMAR, false];
        yield 'Lena moves outside her turn: Mia\'s findings for Omar' => [$miasFindings, self::LENA, false, self::OMAR, false];
        yield 'Omar moves outside his turn, nobody has the ticket: Mia\'s draft' => [$miasFindings, self::OMAR, false, null, false];
        yield 'Omar moves on his turn: Mia\'s closed findings' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Closed), self::OMAR, true, self::OMAR, false];
        yield 'Omar moves on his turn: Mia\'s unshared draft' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, null, RoundState::Open), self::OMAR, true, self::OMAR, false];
        yield 'Omar moves on his turn: Round 0' => [static fn (): Round => self::report([self::screenshot(0)]), self::OMAR, true, self::OMAR, false];
        yield 'Omar moves on his turn: the open checklist round' => [static fn (): Round => self::storedRound(RoundKind::Checklist, self::LENA, 1, RoundState::Open), self::OMAR, true, self::OMAR, false];
    }

    /**
     * @param \Closure(): Round $roundOf
     */
    #[Test]
    #[DataProvider('movesAndWhetherTheyCloseTheRound')]
    public function aMoveClosesAnOpenSharedRoundOnTheMoversTurnOrTheAssigneesFindingsButNotARoundTheMoverSharedNow(\Closure $roundOf, string $moverId, bool $isMoversTurn, ?string $assigneeId, bool $isClosed): void
    {
        $round = $roundOf();
        $before = self::snapshot($round);

        self::assertSame($isClosed, $round->isClosedByMoveOf(new UserId($moverId), $isMoversTurn, null === $assigneeId ? null : new UserId($assigneeId)));
        self::assertSame($before, self::snapshot($round), 'Asking closes nothing.');
    }

    /**
     * @return iterable<string, array{\Closure(): Round, string, string|null, bool}> the round, the sharer, the assignee,
     *                                                                               and whether the share closes it
     */
    public static function sharesAndWhetherTheyCloseTheRound(): iterable
    {
        $miasFindings = static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Open);

        yield 'the assignee shares over Mia\'s findings' => [$miasFindings, self::OMAR, self::OMAR, true];
        yield 'Lena shares over Mia\'s findings for Omar' => [$miasFindings, self::LENA, self::OMAR, false];
        yield 'Omar shares over Mia\'s draft, nobody has the ticket' => [$miasFindings, self::OMAR, null, false];
        yield 'the assignee shares over Mia\'s closed findings' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Closed), self::OMAR, self::OMAR, false];
        yield 'the assignee shares over his own shared round' => [static fn (): Round => self::storedRound(RoundKind::Draft, self::OMAR, 3, RoundState::Open), self::OMAR, self::OMAR, false];
        yield 'the assignee shares over Lena\'s review round' => [static fn (): Round => self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Open), self::OMAR, self::OMAR, false];
    }

    /**
     * @param \Closure(): Round $roundOf
     */
    #[Test]
    #[DataProvider('sharesAndWhetherTheyCloseTheRound')]
    public function onlyTheAssigneesShareClosesAnOpenFindingsRound(\Closure $roundOf, string $sharerId, ?string $assigneeId, bool $isClosed): void
    {
        $round = $roundOf();
        $before = self::snapshot($round);

        self::assertSame($isClosed, $round->isClosedByShareOf(new UserId($sharerId), null === $assigneeId ? null : new UserId($assigneeId)));
        self::assertSame($before, self::snapshot($round), 'Asking closes nothing.');
    }

    #[Test]
    public function findingsAreReadyForTheAssigneeAndAnyOtherRoundForNobodyReviewKnows(): void
    {
        $omar = new UserId(self::OMAR);
        $miasFindings = self::storedRound(RoundKind::Draft, self::MIA, 3, RoundState::Open);

        self::assertEquals($omar, $miasFindings->findingsReadyFor($omar));
        self::assertNull($miasFindings->findingsReadyFor(null), 'Nobody has the ticket.');
        self::assertNull(self::storedRound(RoundKind::Draft, self::OMAR, 3, RoundState::Open)->findingsReadyFor($omar), 'The assignee\'s own shared round is a self-check.');
        self::assertNull(self::storedRound(RoundKind::Review, self::LENA, 3, RoundState::Open)->findingsReadyFor($omar), 'A review round waits for whoever\'s turn it is.');
    }

    #[Test]
    public function aScreenshotIsAddedWithNoTextPinsOrVerdictAndTheVersionGoesUp(): void
    {
        $draft = self::draft();

        $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);

        self::assertCount(1, $draft->screenshots());
        $added = $draft->screenshots()[0];
        self::assertSame(self::NEW_SCREENSHOT, $added->id()->toString());
        self::assertSame(self::MEDIA, $added->mediaId());
        self::assertSame(0, $added->position());
        self::assertNull($added->title());
        self::assertNull($added->summary());
        self::assertSame([], $added->pins());
        self::assertNull($added->verdict());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    #[Test]
    public function aNewScreenshotTakesTheNextPositionAfterTheHighestNeverAGapARemovedOneLeft(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);
        $draft->removeScreenshot($owner, self::screenshotId(1));
        $draft->removeScreenshot($owner, self::screenshotId(2));

        $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);

        self::assertSame([0, 1], self::positionsOf($draft));

        $draft->removeScreenshot($owner, self::screenshotId(0));
        $draft->addScreenshot($owner, new ScreenshotId('0197a3c0-0000-7000-8000-000000000c98'), self::MEDIA);

        self::assertSame([1, 2], self::positionsOf($draft));
    }

    #[Test]
    public function aDraftHoldsTheMaximumNumberOfScreenshotsAndRefusesOneMore(): void
    {
        $draft = self::draftWithScreenshots(Round::MAX_SCREENSHOTS);
        self::assertCount(50, $draft->screenshots());
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA);
            self::fail('A 51st screenshot was added.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A round holds at most 50 screenshots.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aMediaIdThatIsNotAUuidV7IsRefusedAndNothingChanges(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), 'screenshot.png');
            self::fail('A bad media id was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A screenshot needs the id of an uploaded picture.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function removingAScreenshotTakesItsPinsAndKeepsThePositionsOfTheOthers(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(1), [self::pin(1)]);

        $draft->removeScreenshot($owner, self::screenshotId(1));

        self::assertSame([self::screenshotId(0)->toString(), self::screenshotId(2)->toString()], array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $draft->screenshots()));
        self::assertSame([0, 2], self::positionsOf($draft));
        self::assertSame(3, $draft->version());
        self::assertSame(1, $draft->savedVersion());
    }

    #[Test]
    public function aRetakeIsTheNextTryOfTheSameScreenshotAndThePinsStayOnTheTryTheyWerePutOn(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);

        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(0));

        $retake = $draft->screenshots()[2];
        self::assertSame(2, $retake->position());
        self::assertSame(self::screenshotId(0)->toString(), $retake->retakeOf()?->toString());
        self::assertSame([], $retake->pins());
        self::assertSame([1], self::pinNumbersOf($draft, 0));
        self::assertSame([[0, 1, 2], [1, 1, 1], [0, 2, 2]], self::triesOf($draft));
        self::assertSame(3, $draft->version());
    }

    #[Test]
    public function aRetakeOfALaterTryJoinsTheSameChainAndPointsAtItsFirstTry(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(1));

        $draft->addScreenshot($owner, self::screenshotId(3), self::MEDIA, self::screenshotId(2));

        self::assertSame(self::screenshotId(1)->toString(), $draft->screenshots()[3]->retakeOf()?->toString());
        self::assertSame([[0, 1, 1], [1, 1, 3], [1, 2, 3], [1, 3, 3]], self::triesOf($draft));
    }

    #[Test]
    public function aSixthTryIsRefusedWithTheCapMessageAndTheRoundStaysAsItWas(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        for ($position = 2; $position < 2 + Screenshot::MAX_TRIES - 1; ++$position) {
            $draft->addScreenshot($owner, self::screenshotId($position), self::MEDIA, self::screenshotId(0));
        }
        self::assertSame([5, 5], [Screenshot::MAX_TRIES, \count(array_filter(self::triesOf($draft), static fn (array $try): bool => 0 === $try[0]))]);
        $before = self::snapshot($draft);

        foreach ([self::screenshotId(0), self::screenshotId(5)] as $triedAgain) {
            try {
                $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, $triedAgain);
                self::fail('A sixth try was added.');
            } catch (ScreenshotTriesFull $refused) {
                self::assertSame('This screenshot already has 5 tries. Remove one before you add another.', $refused->getMessage());
            }
            self::assertSame($before, self::snapshot($draft));
        }

        $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, self::screenshotId(1));
        self::assertSame([1, 2, 2], self::triesOf($draft)[6]);
    }

    #[Test]
    public function aRemovedTryFreesItsPlaceInTheChainForAnother(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        for ($position = 1; $position < Screenshot::MAX_TRIES; ++$position) {
            $draft->addScreenshot($owner, self::screenshotId($position), self::MEDIA, self::screenshotId(0));
        }
        $draft->removeScreenshot($owner, self::screenshotId(2));

        $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, self::screenshotId(4));

        self::assertSame([[0, 1, 5], [0, 2, 5], [0, 3, 5], [0, 4, 5], [0, 5, 5]], self::triesOf($draft));
    }

    #[Test]
    public function aRetakeOfAScreenshotThisDraftDoesNotHaveIsRefusedAsNotFoundAndTheRoundStaysAsItWas(): void
    {
        $draft = self::draftWithScreenshots(2);
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, new ScreenshotId(self::UNKNOWN_SCREENSHOT));
            self::fail('A retake of an unknown screenshot was added.');
        } catch (RetakenScreenshotNotFound $refused) {
            self::assertSame('The screenshot to retake is not in this draft round.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aRetakeDoesNotPassTheRoundsScreenshotCap(): void
    {
        $draft = self::draftWithScreenshots(Round::MAX_SCREENSHOTS);
        $before = self::snapshot($draft);

        try {
            $draft->addScreenshot(new UserId(self::OMAR), new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, self::screenshotId(0));
            self::fail('A 51st screenshot was added as a try.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A round holds at most 50 screenshots.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function removingAFirstTryMakesTheNextTryTheFirstAndTheLaterOnesPointAtIt(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(0));
        $draft->addScreenshot($owner, self::screenshotId(3), self::MEDIA, self::screenshotId(1));
        $draft->addScreenshot($owner, self::screenshotId(4), self::MEDIA, self::screenshotId(0));

        $draft->removeScreenshot($owner, self::screenshotId(0));

        self::assertSame(
            [[1, null], [2, null], [3, self::screenshotId(1)->toString()], [4, self::screenshotId(2)->toString()]],
            array_map(static fn (Screenshot $screenshot): array => [$screenshot->position(), $screenshot->retakeOf()?->toString()], $draft->screenshots()),
        );
        self::assertSame([[1, 1, 2], [2, 1, 2], [1, 2, 2], [2, 2, 2]], self::triesOf($draft));
    }

    #[Test]
    public function removingALaterTryLeavesTheChainUnderItsFirstTry(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->addScreenshot($owner, self::screenshotId(1), self::MEDIA, self::screenshotId(0));
        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(0));

        $draft->removeScreenshot($owner, self::screenshotId(1));

        self::assertSame([null, self::screenshotId(0)->toString()], array_map(static fn (Screenshot $screenshot): ?string => $screenshot->retakeOf()?->toString(), $draft->screenshots()));
        self::assertSame([[0, 1, 2], [0, 2, 2]], self::triesOf($draft));
    }

    #[Test]
    public function aRestoredRoundKeepsItsTriesAndCountsThemInPositionOrder(): void
    {
        $firstTry = self::screenshotId(4);
        $draft = self::restoredDraftOf([self::screenshot(7)->asTryOf($firstTry), self::screenshot(4), self::screenshot(5)->asTryOf($firstTry), self::screenshot(6)]);

        self::assertSame([[4, 1, 3], [4, 2, 3], [6, 1, 1], [4, 3, 3]], self::triesOf($draft));
    }

    /**
     * @return iterable<string, array{list<Screenshot>}>
     */
    public static function storedChainsThatBreakARule(): iterable
    {
        yield 'a try of a screenshot the round does not have' => [[self::screenshot(0), self::screenshot(1)->asTryOf(new ScreenshotId(self::UNKNOWN_SCREENSHOT))]];
        yield 'a try of a later try' => [[self::screenshot(0), self::screenshot(1)->asTryOf(self::screenshotId(0)), self::screenshot(2)->asTryOf(self::screenshotId(1))]];
    }

    /**
     * @param list<Screenshot> $screenshots
     */
    #[Test]
    #[DataProvider('storedChainsThatBreakARule')]
    public function aStoredRoundWithABrokenChainIsRefused(array $screenshots): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A retake points at the first try of its chain in the same round.');

        self::restoredDraftOf($screenshots);
    }

    #[Test]
    public function aTextEditSetsTrimmedTitleAndSummaryAndKeepsPinsAndVerdict(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->setVerdict($owner, self::screenshotId(0), ScreenshotVerdict::NotRight);

        $draft->editScreenshotText($owner, self::screenshotId(0), '  Cart page ', "Total is wrong\n");

        $edited = $draft->screenshots()[0];
        self::assertSame('Cart page', $edited->title());
        self::assertSame('Total is wrong', $edited->summary());
        self::assertCount(1, $edited->pins());
        self::assertSame(ScreenshotVerdict::NotRight, $edited->verdict());
        self::assertNull($draft->screenshots()[1]->title());
        self::assertSame(4, $draft->version());
    }

    #[Test]
    public function aBlankTextClearsTheTitleAndSummary(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->editScreenshotText($owner, self::screenshotId(0), 'Cart page', 'Total');

        $draft->editScreenshotText($owner, self::screenshotId(0), ' ', null);

        self::assertNull($draft->screenshots()[0]->title());
        self::assertNull($draft->screenshots()[0]->summary());
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function textsOverTheCaps(): iterable
    {
        yield 'title over 120 characters' => [str_repeat('é', 121), null, 'A picture title holds at most 120 characters.'];
        yield 'summary over 600 characters' => [null, str_repeat('é', 601), 'A picture summary holds at most 600 characters.'];
    }

    #[Test]
    #[DataProvider('textsOverTheCaps')]
    public function aTextOverTheCapIsRefusedAndTheOldTextAndVersionStay(?string $title, ?string $summary, string $reason): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->editScreenshotText($owner, self::screenshotId(0), 'Cart page', 'Total');
        $before = self::snapshot($draft);

        try {
            $draft->editScreenshotText($owner, self::screenshotId(0), $title, $summary);
            self::fail('A text over the cap was accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aTitleAndSummaryOfExactlyTheCapsAreAccepted(): void
    {
        $draft = self::draftWithScreenshots(1);

        $draft->editScreenshotText(new UserId(self::OMAR), self::screenshotId(0), str_repeat('é', 120), str_repeat('é', 600));

        self::assertSame(120, mb_strlen((string) $draft->screenshots()[0]->title()));
        self::assertSame(600, mb_strlen((string) $draft->screenshots()[0]->summary()));
    }

    #[Test]
    public function replacingPinsPutsTheNewOnesInNumberOrderInPlaceOfTheOldAndOnlyOnThatPicture(): void
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->replacePins($owner, self::screenshotId(1), [self::pin(5)]);

        $draft->replacePins($owner, self::screenshotId(0), [self::pin(3), self::pin(2)]);

        self::assertSame([2, 3], self::pinNumbersOf($draft, 0));
        self::assertSame([5], self::pinNumbersOf($draft, 1));

        $draft->replacePins($owner, self::screenshotId(0), []);

        self::assertSame([], self::pinNumbersOf($draft, 0));
        self::assertSame(5, $draft->version());
    }

    #[Test]
    public function morePinsThanTheCapAreRefusedAndTheOldPinsStay(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $before = self::snapshot($draft);
        $tooMany = [];
        for ($number = 1; $number <= Screenshot::MAX_PINS + 1; ++$number) {
            $tooMany[] = self::pin($number);
        }

        try {
            $draft->replacePins($owner, self::screenshotId(0), $tooMany);
            self::fail('More pins than the cap were accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('A picture holds at most 100 pins.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertSame([1], self::pinNumbersOf($draft, 0));
    }

    #[Test]
    public function twoPinsWithOneNumberAreRefusedAndTheOldPinsStay(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $before = self::snapshot($draft);

        try {
            $draft->replacePins($owner, self::screenshotId(0), [self::pin(2), self::pin(2)]);
            self::fail('Two pins with one number were accepted.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Pin 2 appears twice on one screenshot.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function theVerdictIsSetChangedAndClearedAndTheDraftCountsThoseThatLookRight(): void
    {
        $draft = self::draftWithScreenshots(3);
        $owner = new UserId(self::OMAR);

        $draft->setVerdict($owner, self::screenshotId(0), ScreenshotVerdict::LooksRight);
        $draft->setVerdict($owner, self::screenshotId(1), ScreenshotVerdict::NotRight);

        self::assertSame(ScreenshotVerdict::LooksRight, $draft->screenshots()[0]->verdict());
        self::assertSame(ScreenshotVerdict::NotRight, $draft->screenshots()[1]->verdict());
        self::assertNull($draft->screenshots()[2]->verdict());
        self::assertSame(1, $draft->screenshotsThatLookRight());

        $draft->setVerdict($owner, self::screenshotId(1), ScreenshotVerdict::LooksRight);
        self::assertSame(2, $draft->screenshotsThatLookRight());

        $draft->setVerdict($owner, self::screenshotId(0), null);
        self::assertNull($draft->screenshots()[0]->verdict());
        self::assertSame(1, $draft->screenshotsThatLookRight());
        self::assertSame(5, $draft->version());
    }

    #[Test]
    public function aPinOnAnEarlierTryIsMarkedFixedOrStillWrongAndClearedAndNoOtherPinChanges(): void
    {
        $draft = self::draftWithTries();
        $owner = new UserId(self::OMAR);

        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);

        self::assertSame([[PinVerdict::Fixed], [], [], [null], [null], []], self::pinVerdictsOf($draft));
        self::assertSame(2, $draft->version());

        $draft->setPinVerdict($owner, self::pin(3)->id(), PinVerdict::StillWrong);
        $draft->setPinVerdict($owner, self::pin(1)->id(), null);

        self::assertSame([[null], [], [], [null], [PinVerdict::StillWrong], []], self::pinVerdictsOf($draft));
        self::assertSame(4, $draft->version());
        self::assertSame(['Header is cut off'], array_map(static fn (Pin $pin): string => $pin->note(), $draft->screenshots()[0]->pins()), 'The mark keeps the pin as it was.');
    }

    #[Test]
    public function fixedStoresTheTryNumberItsChainHadWhenMarkedAndALaterRetakeLeavesIt(): void
    {
        $draft = self::draftWithTries();
        $owner = new UserId(self::OMAR);

        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);
        $draft->setPinVerdict($owner, self::pin(3)->id(), PinVerdict::Fixed);

        self::assertSame([[3], [], [], [null], [2], []], self::fixedOnTriesOf($draft), 'Each pin names the latest try of its own chain: 3 tries, then 2.');

        $draft->addScreenshot($owner, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, self::screenshotId(3));

        self::assertSame([[0, 4, 4]], array_values(array_filter(self::triesOf($draft), static fn (array $try): bool => 4 === $try[1])), 'The chain has a fourth try.');
        self::assertSame([[3], [], [], [null], [2], [], []], self::fixedOnTriesOf($draft), 'The fourth try leaves "Fixed on try 3" as it was.');

        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);

        self::assertSame(4, self::fixedOnTriesOf($draft)[0][0], 'Marking it again names the latest try now.');
    }

    #[Test]
    public function stillWrongAndAClearedMarkNameNoTry(): void
    {
        $draft = self::draftWithTries();
        $owner = new UserId(self::OMAR);
        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);

        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::StillWrong);

        self::assertSame([[PinVerdict::StillWrong], [], [], [null], [null], []], self::pinVerdictsOf($draft));
        self::assertSame([[null], [], [], [null], [null], []], self::fixedOnTriesOf($draft));

        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);
        $draft->setPinVerdict($owner, self::pin(1)->id(), null);

        self::assertSame([[null], [], [], [null], [null], []], self::pinVerdictsOf($draft));
        self::assertSame([[null], [], [], [null], [null], []], self::fixedOnTriesOf($draft));
    }

    #[Test]
    public function removingATryClearsAMarkThatNamedItAndCountsOneLessOnAMarkThatNamedALaterTry(): void
    {
        $owner = new UserId(self::OMAR);
        $earlierRemoved = self::draftWithTries();
        $earlierRemoved->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);
        $earlierRemoved->setPinVerdict($owner, self::pin(3)->id(), PinVerdict::Fixed);

        $earlierRemoved->removeScreenshot($owner, self::screenshotId(2));

        self::assertSame([[PinVerdict::Fixed], [], [null], [PinVerdict::Fixed], []], self::pinVerdictsOf($earlierRemoved));
        self::assertSame([[2], [], [null], [2], []], self::fixedOnTriesOf($earlierRemoved), 'Try 3 is try 2 now; the other chain keeps its mark.');

        $fixingTryRemoved = self::draftWithTries();
        $fixingTryRemoved->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);
        $version = $fixingTryRemoved->version();

        $fixingTryRemoved->removeScreenshot($owner, self::screenshotId(3));

        self::assertSame([[null], [], [], [null], []], self::pinVerdictsOf($fixingTryRemoved), 'The try that fixed it is gone, so is the mark.');
        self::assertSame([[null], [], [], [null], []], self::fixedOnTriesOf($fixingTryRemoved));
        self::assertSame($version + 1, $fixingTryRemoved->version());
    }

    #[Test]
    public function removingATryAfterTheOneThatFixedAPinLeavesItsMark(): void
    {
        $draft = self::draftWithScreenshots(1);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->addScreenshot($owner, self::screenshotId(1), self::MEDIA, self::screenshotId(0));
        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::Fixed);
        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(0));

        $draft->removeScreenshot($owner, self::screenshotId(2));

        self::assertSame([[PinVerdict::Fixed], []], self::pinVerdictsOf($draft));
        self::assertSame([[2], []], self::fixedOnTriesOf($draft));
    }

    /**
     * A pin on the latest try takes no mark and shows no chips, so a mark left there could never be taken back.
     */
    #[Test]
    public function removingTheLatestTryClearsEveryMarkOnTheTryThatIsLatestNowAndNoOther(): void
    {
        $draft = self::draftWithTries();
        $owner = new UserId(self::OMAR);
        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::StillWrong);
        $draft->setPinVerdict($owner, self::pin(3)->id(), PinVerdict::StillWrong);
        $version = $draft->version();

        $draft->removeScreenshot($owner, self::screenshotId(5));

        self::assertSame([[PinVerdict::StillWrong], [], [], [null], [null]], self::pinVerdictsOf($draft), 'Chain C\'s try 1 is its latest now; chain A keeps its mark.');
        self::assertSame([[null], [], [], [null], [null]], self::fixedOnTriesOf($draft));
        self::assertSame($version + 1, $draft->version());

        $draft->removeScreenshot($owner, self::screenshotId(3));

        self::assertSame([[PinVerdict::StillWrong], [], [], [null]], self::pinVerdictsOf($draft), 'Try 2 of chain A is its latest now; try 1 is still an earlier try.');

        $draft->removeScreenshot($owner, self::screenshotId(2));

        self::assertSame([[null], [], [null]], self::pinVerdictsOf($draft), 'Try 1 of chain A is its latest now.');
        self::assertSame(['Header is cut off'], array_map(static fn (Pin $pin): string => $pin->note(), $draft->screenshots()[0]->pins()), 'The pin stays, only its mark goes.');
    }

    #[Test]
    public function removingATryBeforeTheLatestLeavesTheMarksOfTheChainsOtherTries(): void
    {
        $draft = self::draftWithTries();
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(2), [self::pin(4)]);
        $draft->setPinVerdict($owner, self::pin(1)->id(), PinVerdict::StillWrong);
        $draft->setPinVerdict($owner, self::pin(4)->id(), PinVerdict::StillWrong);

        $draft->removeScreenshot($owner, self::screenshotId(0));

        self::assertSame([[], [PinVerdict::StillWrong], [null], [null], []], self::pinVerdictsOf($draft), 'Try 3 is still the latest, so the mark on the try before it stays.');
    }

    #[Test]
    public function aPinOnTheLatestTryOfItsChainTakesNoMarkAndTheDraftStaysAsItWas(): void
    {
        $draft = self::draftWithTries();
        $before = self::snapshot($draft);

        try {
            $draft->setPinVerdict(new UserId(self::OMAR), self::pin(2)->id(), PinVerdict::Fixed);
            self::fail('A pin on the latest try was marked.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a pin on an earlier try can be marked fixed or still wrong.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aPinTheDraftDoesNotHaveIsRefusedAndTheDraftStaysAsItWas(): void
    {
        $draft = self::draftWithTries();
        $before = self::snapshot($draft);

        try {
            $draft->setPinVerdict(new UserId(self::OMAR), self::pin(9)->id(), PinVerdict::Fixed);
            self::fail('An unknown pin was marked.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round has no such pin.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @return iterable<string, array{\Closure(Round, UserId): void}>
     */
    public static function draftMutations(): iterable
    {
        yield 'add a screenshot' => [static fn (Round $round, UserId $actor) => $round->addScreenshot($actor, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA)];
        yield 'add a try' => [static fn (Round $round, UserId $actor) => $round->addScreenshot($actor, new ScreenshotId(self::NEW_SCREENSHOT), self::MEDIA, self::screenshotId(0))];
        yield 'remove a screenshot' => [static fn (Round $round, UserId $actor) => $round->removeScreenshot($actor, self::screenshotId(0))];
        yield 'edit the text' => [static fn (Round $round, UserId $actor) => $round->editScreenshotText($actor, self::screenshotId(0), 'Title', 'Summary')];
        yield 'replace the pins' => [static fn (Round $round, UserId $actor) => $round->replacePins($actor, self::screenshotId(0), [self::pin(1)])];
        yield 'set the verdict' => [static fn (Round $round, UserId $actor) => $round->setVerdict($actor, self::screenshotId(0), ScreenshotVerdict::LooksRight)];
        yield 'mark a pin' => [static fn (Round $round, UserId $actor) => $round->setPinVerdict($actor, self::pin(1)->id(), PinVerdict::Fixed)];
        yield 'delete' => [static fn (Round $round, UserId $actor) => $round->delete($actor, new \DateTimeImmutable('2026-03-06 08:00:00+00:00'))];
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function onlyTheOwnerChangesADraft(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::LENA));
            self::fail('Another person changed the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can change it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aSharedDraftNoLongerChanges(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('A shared draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is shared, so it no longer changes.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aDeletedDraftNoLongerChanges(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('A deleted draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aClosedDraftThatWasNotDeletedNoLongerChanges(\Closure $mutation): void
    {
        $closed = self::restoredDraft(RoundState::Closed);
        $before = self::snapshot($closed);

        try {
            $mutation($closed, new UserId(self::OMAR));
            self::fail('A closed draft was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round is closed.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($closed));
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('draftMutations')]
    public function aReportRoundIsNeverChangedThisWay(\Closure $mutation): void
    {
        $report = self::report([self::screenshot(0)]);
        $before = self::snapshot($report);

        try {
            $mutation($report, new UserId(self::LENA));
            self::fail('A report round was changed.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round can be changed this way.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($report));
    }

    /**
     * @return iterable<string, array{\Closure(Round, UserId): void}>
     */
    public static function mutationsOfAPictureTheDraftDoesNotHave(): iterable
    {
        $unknown = new ScreenshotId(self::UNKNOWN_SCREENSHOT);
        yield 'remove' => [static fn (Round $round, UserId $actor) => $round->removeScreenshot($actor, $unknown)];
        yield 'edit the text' => [static fn (Round $round, UserId $actor) => $round->editScreenshotText($actor, $unknown, 'Title', null)];
        yield 'replace the pins' => [static fn (Round $round, UserId $actor) => $round->replacePins($actor, $unknown, [self::pin(1)])];
        yield 'set the verdict' => [static fn (Round $round, UserId $actor) => $round->setVerdict($actor, $unknown, ScreenshotVerdict::NotRight)];
    }

    /**
     * @param \Closure(Round, UserId): void $mutation
     */
    #[Test]
    #[DataProvider('mutationsOfAPictureTheDraftDoesNotHave')]
    public function anUnknownScreenshotIsRefusedAndTheDraftStaysAsItWas(\Closure $mutation): void
    {
        $draft = self::draftWithScreenshots(2);
        $before = self::snapshot($draft);

        try {
            $mutation($draft, new UserId(self::OMAR));
            self::fail('An unknown screenshot was accepted.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round has no such screenshot.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function deletingADraftClosesItAndKeepsItsPicturesForRetention(): void
    {
        $draft = self::draftWithScreenshots(2);
        $deletedAt = new \DateTimeImmutable('2026-03-06 08:00:00+00:00');

        $draft->delete(new UserId(self::OMAR), $deletedAt);

        self::assertSame(RoundState::Closed, $draft->state());
        self::assertSame($deletedAt, $draft->deletedAt());
        self::assertCount(2, $draft->screenshots());
        self::assertSame(2, $draft->version());
        self::assertSame(1, $draft->savedVersion());
        self::assertNull($draft->number());
    }

    #[Test]
    public function aDraftCannotBeDeletedTwiceAndKeepsItsFirstDeletionTime(): void
    {
        $draft = self::draft();
        $firstDeletedAt = new \DateTimeImmutable('2026-03-06 08:00:00+00:00');
        $draft->delete(new UserId(self::OMAR), $firstDeletedAt);

        try {
            $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-07 08:00:00+00:00'));
            self::fail('A draft was deleted twice.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($firstDeletedAt, $draft->deletedAt());
        self::assertSame(2, $draft->version());
    }

    #[Test]
    public function theOwnerRecordsThatTheySharedAfterSharing(): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $draft->decide(new UserId(self::OMAR), DraftDecision::Shared, $decidedAt);

        self::assertSame(DraftDecision::Shared, $draft->decision());
        self::assertSame($decidedAt, $draft->decidedAt());
        self::assertSame(3, $draft->version());
    }

    #[Test]
    public function theOwnerKeepsAnUnsharedDraftPrivate(): void
    {
        $draft = self::draft();
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, $decidedAt);

        self::assertSame(DraftDecision::KeptPrivate, $draft->decision());
        self::assertSame($decidedAt, $draft->decidedAt());
        self::assertSame(2, $draft->version());
        self::assertTrue($draft->isUnsharedDraft());
    }

    #[Test]
    public function aReportRoundCannotBeDecidedAbout(): void
    {
        $report = self::report([self::screenshot(0)]);
        $before = self::snapshot($report);

        try {
            $report->decide(new UserId(self::LENA), DraftDecision::Shared, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A report round was decided about.');
        } catch (\DomainException $refused) {
            self::assertSame('Only a draft round is shared or kept private.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($report));
    }

    #[Test]
    public function onlyTheOwnerDecidesAboutADraft(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::LENA), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('Another person decided about the draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Only the person who owns a draft round can decide about it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aDeletedDraftCannotBeDecidedAbout(): void
    {
        $draft = self::draft();
        $draft->delete(new UserId(self::OMAR), new \DateTimeImmutable('2026-03-05 11:00:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A deleted draft was decided about.');
        } catch (\DomainException $refused) {
            self::assertSame('This draft round was deleted.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
    }

    #[Test]
    public function aDraftIsDecidedAboutOnceAndKeepsItsFirstChoice(): void
    {
        $draft = self::draft();
        $firstDecidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');
        $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, $firstDecidedAt);
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-06 09:00:00+00:00'));
            self::fail('A second decision was recorded.');
        } catch (\DomainException $refused) {
            self::assertSame('You decided about this draft round already.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertSame($firstDecidedAt, $draft->decidedAt());
    }

    #[Test]
    public function sharedCannotBeRecordedBeforeTheDraftIsShared(): void
    {
        $draft = self::draft();
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::Shared, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('Shared was recorded on an unshared draft.');
        } catch (\DomainException $refused) {
            self::assertSame('Share the draft round before recording that you shared it.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertNull($draft->decision());
    }

    #[Test]
    public function aSharedDraftCannotBeKeptPrivate(): void
    {
        $draft = self::draftWithScreenshots(1);
        $draft->share(new UserId(self::OMAR), 1, new \DateTimeImmutable('2026-03-05 11:15:00+00:00'));
        $before = self::snapshot($draft);

        try {
            $draft->decide(new UserId(self::OMAR), DraftDecision::KeptPrivate, new \DateTimeImmutable('2026-03-05 11:16:00+00:00'));
            self::fail('A shared draft was kept private.');
        } catch (\DomainException $refused) {
            self::assertSame('A shared draft round cannot be kept private.', $refused->getMessage());
        }

        self::assertSame($before, self::snapshot($draft));
        self::assertNull($draft->decision());
    }

    #[Test]
    public function aRestoredRoundKeepsItsVersionAsTheOneToSaveOverAndCountsFromThere(): void
    {
        $draft = self::restoredDraft(RoundState::Open, 7);

        self::assertSame(7, $draft->version());
        self::assertSame(7, $draft->savedVersion());

        $draft->setVerdict(new UserId(self::OMAR), self::screenshotId(0), ScreenshotVerdict::LooksRight);

        self::assertSame(8, $draft->version());
        self::assertSame(7, $draft->savedVersion());
    }

    #[Test]
    public function aRestoredRoundKeepsItsScreenshotsInPositionOrder(): void
    {
        $draft = Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, RoundState::Open, 1, [self::screenshot(4), self::screenshot(0), self::screenshot(2)], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);

        self::assertSame([0, 2, 4], self::positionsOf($draft));
    }

    /**
     * @return iterable<string, array{string, int, ?int, ?\DateTimeImmutable, ?DraftDecision, ?\DateTimeImmutable}>
     */
    public static function storedRoundsThatBreakARule(): iterable
    {
        $time = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $versionMessage = 'A round version starts at 1.';
        $pairMessage = 'A round has a number exactly when it is shared, and a decision exactly when it has its time.';
        yield 'version 0' => [$versionMessage, 0, null, null, null, null];
        yield 'negative version' => [$versionMessage, -3, null, null, null, null];
        yield 'number without share time' => [$pairMessage, 1, 1, null, null, null];
        yield 'share time without number' => [$pairMessage, 1, null, $time, null, null];
        yield 'decision without time' => [$pairMessage, 1, null, null, DraftDecision::KeptPrivate, null];
        yield 'decision time without decision' => [$pairMessage, 1, null, null, null, $time];
    }

    #[Test]
    #[DataProvider('storedRoundsThatBreakARule')]
    public function aStoredRoundThatBreaksARuleIsRefused(string $message, int $version, ?int $number, ?\DateTimeImmutable $sharedAt, ?DraftDecision $decision, ?\DateTimeImmutable $decidedAt): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), $number, $sharedAt, RoundState::Open, $version, [], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, $decision, $decidedAt);
    }

    #[Test]
    public function aStoredSharedAndDecidedRoundIsRestoredWithEverythingItHad(): void
    {
        $sharedAt = new \DateTimeImmutable('2026-03-05 11:15:00+00:00');
        $decidedAt = new \DateTimeImmutable('2026-03-05 11:16:00+00:00');

        $round = Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), 2, $sharedAt, RoundState::Open, 4, [], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, DraftDecision::Shared, $decidedAt);

        self::assertSame(2, $round->number());
        self::assertSame($sharedAt, $round->sharedAt());
        self::assertSame(DraftDecision::Shared, $round->decision());
        self::assertSame($decidedAt, $round->decidedAt());
        self::assertFalse($round->isUnsharedDraft());
    }

    /**
     * @param list<Screenshot> $screenshots
     */
    private static function report(array $screenshots): Round
    {
        return Round::report(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), $screenshots, new \DateTimeImmutable('2026-03-04 09:30:00+00:00'));
    }

    private static function draft(): Round
    {
        return Round::draft(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), new \DateTimeImmutable('2026-03-04 10:00:00+00:00'));
    }

    /**
     * Omar's draft with $count screenshots at positions 0 up, as a read gives it at version 1.
     */
    private static function draftWithScreenshots(int $count): Round
    {
        $screenshots = [];
        for ($position = 0; $position < $count; ++$position) {
            $screenshots[] = self::screenshot($position);
        }

        return Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, RoundState::Open, 1, $screenshots, new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);
    }

    /**
     * Omar's draft with chain A (0, 2, 3), B (1) and chain C (4, 5); pin 1 on A's try 1, pin 2 on A's try 3 and pin 3 on
     * C's try 1; read back at version 1.
     */
    private static function draftWithTries(): Round
    {
        $draft = self::draftWithScreenshots(2);
        $owner = new UserId(self::OMAR);
        $draft->replacePins($owner, self::screenshotId(0), [self::pin(1)]);
        $draft->addScreenshot($owner, self::screenshotId(2), self::MEDIA, self::screenshotId(0));
        $draft->addScreenshot($owner, self::screenshotId(3), self::MEDIA, self::screenshotId(2));
        $draft->replacePins($owner, self::screenshotId(3), [self::pin(2)]);
        $draft->addScreenshot($owner, self::screenshotId(4), self::MEDIA);
        $draft->replacePins($owner, self::screenshotId(4), [self::pin(3)]);
        $draft->addScreenshot($owner, self::screenshotId(5), self::MEDIA, self::screenshotId(4));

        return Round::restored($draft->id(), $draft->organizationId(), $draft->ticketId(), RoundKind::Draft, $owner, null, null, RoundState::Open, 1, $draft->screenshots(), $draft->createdAt(), null, null, null);
    }

    /**
     * @param list<Screenshot> $screenshots
     */
    private static function restoredDraftOf(array $screenshots): Round
    {
        return Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, RoundState::Open, 1, $screenshots, new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);
    }

    /**
     * Each screenshot's try as [position of the chain's first try, number, count], by position.
     *
     * @return list<array{int, int, int}>
     */
    private static function triesOf(Round $round): array
    {
        $positionById = [];
        $countByChain = [];
        foreach ($round->screenshots() as $screenshot) {
            $positionById[$screenshot->id()->toString()] = $screenshot->position();
            $chain = $screenshot->firstTryId()->toString();
            $countByChain[$chain] = ($countByChain[$chain] ?? 0) + 1;
        }
        $numberByChain = [];
        $tries = [];
        foreach ($round->screenshots() as $screenshot) {
            $chain = $screenshot->firstTryId()->toString();
            $numberByChain[$chain] = ($numberByChain[$chain] ?? 0) + 1;
            $tries[] = [$positionById[$chain], $numberByChain[$chain], $countByChain[$chain]];
        }

        return $tries;
    }

    private static function restoredDraft(RoundState $state, int $version = 1): Round
    {
        return Round::restored(new RoundId(self::ROUND), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), RoundKind::Draft, new UserId(self::OMAR), null, null, $state, $version, [self::screenshot(0)], new \DateTimeImmutable('2026-03-04 10:00:00+00:00'), null, null, null);
    }

    /**
     * A round as a read gives it at version 1, with one screenshot; shared exactly when it has a number.
     */
    private static function storedRound(RoundKind $kind, string $ownerId, ?int $number, RoundState $state, bool $isDeleted = false, ?DraftDecision $decision = null): Round
    {
        $createdAt = new \DateTimeImmutable('2026-03-04 10:00:00+00:00');

        return Round::restored(
            new RoundId(self::ROUND),
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            $kind,
            new UserId($ownerId),
            $number,
            null === $number ? null : new \DateTimeImmutable('2026-03-05 11:15:00+00:00'),
            $state,
            1,
            [self::screenshot(0)],
            $createdAt,
            $isDeleted ? new \DateTimeImmutable('2026-03-05 12:00:00+00:00') : null,
            $decision,
            null === $decision ? null : new \DateTimeImmutable('2026-03-05 11:15:00+00:00'),
        );
    }

    private static function screenshotId(int $position): ScreenshotId
    {
        return new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xC00 + $position));
    }

    private static function screenshot(int $position): Screenshot
    {
        return Screenshot::of(self::screenshotId($position), self::MEDIA, $position, null, null, []);
    }

    private static function pin(int $number): Pin
    {
        return Pin::placedBy(new PinId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0xA00 + $number)), $number, 10.0, 20.0, 'Header is cut off', new UserId(self::OMAR));
    }

    /**
     * @return list<int>
     */
    private static function positionsOf(Round $round): array
    {
        return array_map(static fn (Screenshot $screenshot): int => $screenshot->position(), $round->screenshots());
    }

    /**
     * @return list<int>
     */
    private static function pinNumbersOf(Round $round, int $index): array
    {
        return array_map(static fn (Pin $pin): int => $pin->number(), $round->screenshots()[$index]->pins());
    }

    /**
     * @return list<list<PinVerdict|null>> the verdict of each pin, by screenshot position
     */
    private static function pinVerdictsOf(Round $round): array
    {
        return array_map(static fn (Screenshot $screenshot): array => array_map(static fn (Pin $pin): ?PinVerdict => $pin->verdict(), $screenshot->pins()), $round->screenshots());
    }

    /**
     * @return list<list<int|null>> each pin's "Fixed on try N", by screenshot position and pin
     */
    private static function fixedOnTriesOf(Round $round): array
    {
        return array_map(static fn (Screenshot $screenshot): array => array_map(static fn (Pin $pin): ?int => $pin->fixedOnTry(), $screenshot->pins()), $round->screenshots());
    }

    /**
     * Everything a refused change must leave as it was.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Round $round): array
    {
        return [
            'number' => $round->number(),
            'sharedAt' => $round->sharedAt()?->format('c'),
            'state' => $round->state(),
            'version' => $round->version(),
            'savedVersion' => $round->savedVersion(),
            'deletedAt' => $round->deletedAt()?->format('c'),
            'decision' => $round->decision(),
            'decidedAt' => $round->decidedAt()?->format('c'),
            'screenshots' => array_map(static fn (Screenshot $screenshot): array => [
                $screenshot->id()->toString(),
                $screenshot->mediaId(),
                $screenshot->position(),
                $screenshot->title(),
                $screenshot->summary(),
                $screenshot->verdict(),
                $screenshot->retakeOf()?->toString(),
                array_map(static fn (Pin $pin): array => [$pin->id()->toString(), $pin->number(), $pin->verdict(), $pin->fixedOnTry()], $screenshot->pins()),
            ], $round->screenshots()),
        ];
    }
}
