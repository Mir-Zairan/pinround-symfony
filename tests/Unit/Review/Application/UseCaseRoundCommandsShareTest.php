<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\RoundRefused;
use App\Review\Application\DraftRoundAsRead;
use App\Review\Application\ReportPictures;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Media\Application\InMemoryTransactions;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketNotFound;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One open shared round per ticket (ADR-0048) on UseCaseRoundCommands' share, over InMemoryRounds inside
 * InMemoryTransactions, so every read and save needs an open transaction as on DbalRounds. Omar is the ticket's
 * assignee, Lena its lead and reviewer, Mia its tester. The rounds every share must look past are seeded first: the
 * report (Round 0), the open checklist round 1, a closed review round 2, Lena's deleted draft, an open round of another
 * ticket and an open round of another organization on the same ticket id. Each test adds the open round it is about.
 */
final class UseCaseRoundCommandsShareTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002f02';
    private const string SHOP = '0197a3c0-0000-7000-8000-000000002f11';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000002001';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000002002';
    private const string UNASSIGNED_TICKET = '0197a3c0-0000-7000-8000-000000002003';
    /** A ticket the ticket queries do not find, as one of another organization. */
    private const string UNREADABLE_TICKET = '0197a3c0-0000-7000-8000-000000002004';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000002099';
    private const string LENA = '0197a3c0-0000-7000-8000-000000002b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002b03';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000002d01';

    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-000000002e01';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-000000002e02';
    private const string LENAS_EMPTY_DRAFT = '0197a3c0-0000-7000-8000-000000002e03';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000002e04';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-000000002e05';
    private const string CLOSED_REVIEW = '0197a3c0-0000-7000-8000-000000002e06';
    private const string LENAS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-000000002e07';
    private const string OTHER_TICKET_OPEN_REVIEW = '0197a3c0-0000-7000-8000-000000002e08';
    private const string BETA_BAKERY_OPEN_REVIEW = '0197a3c0-0000-7000-8000-000000002e09';
    /** The open round a test adds, numbered 3. */
    private const string OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e0a';
    /** A second open round a test adds, numbered 4. */
    private const string SECOND_OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e0b';
    private const string UNASSIGNED_DRAFT = '0197a3c0-0000-7000-8000-000000002e0c';
    private const string UNREADABLE_DRAFT = '0197a3c0-0000-7000-8000-000000002e0d';

    private TenantContext $tenantContext;
    private InMemoryTransactions $transactions;
    private InMemoryRounds $rounds;
    private RecordingChecklistCommands $checklistCommands;
    private InMemoryTicketTurnQueries $tickets;
    private UseCaseRoundCommands $commands;
    private int $screenshotSeeds = 0;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->rounds = new InMemoryRounds($this->tenantContext, $this->transactions);
        // A run that throws puts the stored rounds back, as the caller's rolled-back transaction does on DbalRounds.
        $this->transactions->enlist(function (): \Closure {
            $stored = $this->rounds->stored;

            return function () use ($stored): void {
                $this->rounds->stored = $stored;
            };
        });
        $this->checklistCommands = new RecordingChecklistCommands();
        $this->tickets = new InMemoryTicketTurnQueries([
            self::ticketFacts(self::TICKET, new UserId(self::OMAR)),
            self::ticketFacts(self::OTHER_TICKET, new UserId(self::OMAR)),
            self::ticketFacts(self::UNASSIGNED_TICKET, null),
        ], new TicketId(self::BROKEN_TICKET));
        $this->commands = $this->commandsOver($this->rounds);

        $this->inTransaction(function (): void {
            $this->rounds->add(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [$this->screenshot()], self::earlier()));
            $this->rounds->add($this->stored(self::CHECKLIST, self::ACME_FOODS, self::TICKET, RoundKind::Checklist, self::LENA, null, RoundState::Open));
            $this->rounds->add($this->stored(self::CLOSED_REVIEW, self::ACME_FOODS, self::TICKET, RoundKind::Review, self::OMAR, 2, RoundState::Closed));
            $this->rounds->add($this->draftWithAScreenshot(self::OMARS_DRAFT, self::TICKET, self::OMAR));
            $this->rounds->add($this->draftWithAScreenshot(self::MIAS_DRAFT, self::TICKET, self::MIA));
            $this->rounds->add(self::draft(self::LENAS_EMPTY_DRAFT, self::TICKET, self::LENA));
            $deleted = $this->draftWithAScreenshot(self::LENAS_DELETED_DRAFT, self::TICKET, self::LENA);
            $deleted->delete(new UserId(self::LENA), self::earlier());
            $this->rounds->add($deleted);
            $this->rounds->add($this->stored(self::OTHER_TICKET_OPEN_REVIEW, self::ACME_FOODS, self::OTHER_TICKET, RoundKind::Review, self::OMAR, 7, RoundState::Open));
            $this->rounds->add($this->stored(self::BETA_BAKERY_OPEN_REVIEW, self::BETA_BAKERY, self::TICKET, RoundKind::Review, self::OMAR, 9, RoundState::Open));
        });
    }

    #[Test]
    public function roundZeroTheChecklistRoundAndClosedOtherTicketsAndOtherOrganizationsRoundsNeverBlockAShare(): void
    {
        $before = $this->snapshot();

        $number = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        self::assertSame(3, $number, 'One above the closed review round 2; round 7 and 9 are another ticket\'s and another organization\'s.');
        self::assertSame([3, RoundState::Open], [$this->storedRound(self::OMARS_DRAFT)->number(), $this->storedRound(self::OMARS_DRAFT)->state()]);
        self::assertSame(self::without($before, self::OMARS_DRAFT), self::without($this->snapshot(), self::OMARS_DRAFT), 'No other round changed, the open checklist round neither.');
        self::assertSame([self::TICKET], $this->rounds->lockedOpenRoundTicketIds);
        self::assertSame([self::TICKET], $this->rounds->numberedTicketIds);
    }

    /**
     * ADR-0048: the team sees the latest try of each screenshot, and the earlier tries stay with the owner. Omar's
     * draft holds its seeded screenshot (S) and chain A of three tries, added after S, with a pin on A's try 1.
     */
    #[Test]
    public function theSharedRoundKeepsEveryTryForTheOwnerAndShowsTheTeamOnlyTheLatestOfEachScreenshot(): void
    {
        $tries = $this->addChainOfThreeTriesToOmarsDraft();
        [$seeded, $tryOne, , $tryThree] = $tries;

        $number = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        self::assertSame(3, $number);
        $shared = $this->storedRound(self::OMARS_DRAFT);
        self::assertSame($tries, array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $shared->screenshots()), 'Every try is kept.');
        self::assertSame(
            [[$tryOne, ['0197a3c0-0000-7000-8000-000000002c11']]],
            array_values(array_filter(
                array_map(static fn (Screenshot $screenshot): array => [$screenshot->id()->toString(), array_map(static fn (Pin $pin): string => $pin->id()->toString(), $screenshot->pins())], $shared->screenshots()),
                static fn (array $screenshotPins): bool => [] !== $screenshotPins[1],
            )),
            'Try 1 keeps its pin.',
        );
        self::assertSame(
            [[$seeded, 0], [$tryThree, 3]],
            array_map(static fn (Screenshot $screenshot): array => [$screenshot->id()->toString(), $screenshot->position()], $shared->latestTries()),
        );
        self::assertSame([[self::OMARS_DRAFT, '2026-03-04 09:30:00']], $this->checklistCommands->findingsCreated);

        $read = $this->inTransaction(fn (): DraftRoundAsRead => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::OMAR)));
        self::assertCount(2, $read->screenshots, 'Make it a new ticket copies the latest try of each screenshot only.');
        self::assertCount(4, $this->storedRound(self::OMARS_DRAFT)->screenshots(), 'Omar still reads his earlier tries.');
        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOf(fn () => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::MIA))));
        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOf(fn () => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::LENA))));
    }

    #[Test]
    public function aShareAtHandOffAlsoKeepsEveryTry(): void
    {
        $tries = $this->addChainOfThreeTriesToOmarsDraft();
        [$seeded, , , $tryThree] = $tries;

        $this->inTransaction(fn (): int => $this->commands->shareDraftAtHandOff(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        $shared = $this->storedRound(self::OMARS_DRAFT);
        self::assertSame($tries, array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $shared->screenshots()));
        self::assertSame([$seeded, $tryThree], array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $shared->latestTries()));
    }

    #[Test]
    public function aRefusedShareKeepsEveryTryInTheDraft(): void
    {
        $tries = $this->addChainOfThreeTriesToOmarsDraft();
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Review, self::OMAR, 3);

        $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertSame($tries, array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $this->storedRound(self::OMARS_DRAFT)->screenshots()));
    }

    /**
     * Until the share, the earlier tries are the owner's own: Omar reads all of them, Mia reads none, and deleting the
     * draft takes them with it.
     */
    #[Test]
    public function theEarlierTriesStayReadableToTheOwnerOnlyAndGoWhenTheDraftIsDeleted(): void
    {
        $this->addChainOfThreeTriesToOmarsDraft();

        $read = $this->inTransaction(fn (): DraftRoundAsRead => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::OMAR)));
        self::assertCount(2, $read->screenshots, 'The contents give the latest try of each screenshot.');
        self::assertCount(4, $this->storedRound(self::OMARS_DRAFT)->screenshots());
        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOf(fn () => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::MIA))));

        $this->inTransaction(function (): void {
            $this->commands->deleteDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at());
        });

        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOf(fn () => $this->commands->contentsOf(self::OMARS_DRAFT, new UserId(self::OMAR))));
        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOf(fn () => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at())));
        self::assertNull($this->storedRound(self::OMARS_DRAFT)->number(), 'A deleted draft never reaches the team.');
    }

    #[Test]
    public function aShareWhileAReviewRoundIsOpenIsRefusedWithItsNumberAndTheDraftStaysUnsharedAndUnnumbered(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Review, self::OMAR, 3);

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertTrue($refused->isSharingBlocked());
        self::assertSame([false, false, false], [$refused->isNotFound(), $refused->isReadOnly(), $refused->isInvalidContent()]);
        self::assertSame(3, $refused->openRoundNumber());
        self::assertNull($refused->readyForUserId(), 'A review round is ready for whoever\'s turn it is now, which Review does not read.');
        self::assertSame([null, null], [$this->storedRound(self::OMARS_DRAFT)->number(), $this->storedRound(self::OMARS_DRAFT)->sharedAt()]);
        self::assertSame([self::TICKET], $this->rounds->lockedOpenRoundTicketIds, 'The check ran under the lock.');
        self::assertSame([], $this->rounds->numberedTicketIds, 'No number is read for a refused share.');
        self::assertSame([], $this->checklistCommands->findingsCreated);
    }

    #[Test]
    public function aShareAtHandOffWhileARoundIsOpenIsRefusedTheSameWayAndRecordsNoChoice(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Review, self::OMAR, 3);

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraftAtHandOff(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertTrue($refused->isSharingBlocked());
        self::assertNull($this->storedRound(self::OMARS_DRAFT)->decision(), 'The draft may still be kept private.');
    }

    #[Test]
    public function theAssigneesOwnOpenSharedDraftIsNoFindingsRoundSoTheirNextShareWaitsForItsVerdict(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::OMAR, 3);

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertSame([3, null], [$refused->openRoundNumber(), $refused->readyForUserId()]);
    }

    #[Test]
    public function theAssigneesShareClosesTheOpenFindingsRoundForThemAndGetsTheNextNumber(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::MIA, 3);
        $before = $this->snapshot();
        $savesBefore = $this->rounds->savedRounds;

        $number = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        self::assertSame(4, $number);
        $findings = $this->storedRound(self::OPEN_ROUND);
        self::assertSame([RoundState::Closed, 3, $before[self::OPEN_ROUND]['version'] + 1], [$findings->state(), $findings->number(), $findings->version()]);
        $shared = $this->storedRound(self::OMARS_DRAFT);
        self::assertSame([4, RoundState::Open], [$shared->number(), $shared->state()]);
        self::assertEquals(self::at(), $shared->sharedAt());
        self::assertSame($savesBefore + 2, $this->rounds->savedRounds, 'The share and the close, once each.');
        self::assertSame(self::without($before, self::OMARS_DRAFT, self::OPEN_ROUND), self::without($this->snapshot(), self::OMARS_DRAFT, self::OPEN_ROUND));
        self::assertSame([[self::OMARS_DRAFT, '2026-03-04 09:30:00']], $this->checklistCommands->findingsCreated);
    }

    /**
     * A ticket from before ADR-0048 may hold two open findings rounds; the assignee's share closes both.
     */
    #[Test]
    public function theAssigneesShareClosesEveryOpenFindingsRoundForThem(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::MIA, 3);
        $this->addOpenRound(self::SECOND_OPEN_ROUND, RoundKind::Draft, self::LENA, 4);

        $number = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        self::assertSame(5, $number);
        self::assertSame(
            [RoundState::Closed, RoundState::Closed],
            [$this->storedRound(self::OPEN_ROUND)->state(), $this->storedRound(self::SECOND_OPEN_ROUND)->state()],
        );
    }

    #[Test]
    public function anOpenRoundTheAssigneeMayNotCloseRefusesTheirShareAndLeavesTheirFindingsRoundOpen(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::MIA, 3);
        $this->addOpenRound(self::SECOND_OPEN_ROUND, RoundKind::Review, self::OMAR, 4);

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 4 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertSame([4, null], [$refused->openRoundNumber(), $refused->readyForUserId()]);
        self::assertSame(RoundState::Open, $this->storedRound(self::OPEN_ROUND)->state());
    }

    #[Test]
    public function anyoneButTheAssigneeIsRefusedWhileAFindingsRoundIsOpenAndTheRoundIsReadyForTheAssignee(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::LENA, 3);

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), self::at()),
        );

        self::assertTrue($refused->isSharingBlocked());
        self::assertSame(3, $refused->openRoundNumber());
        self::assertEquals(new UserId(self::OMAR), $refused->readyForUserId());
        self::assertSame(RoundState::Open, $this->storedRound(self::OPEN_ROUND)->state());
    }

    #[Test]
    public function onATicketWithNoAssigneeNoShareClosesAnOpenSharedDraftAndNobodyIsNamedForIt(): void
    {
        $this->inTransaction(function (): void {
            $this->rounds->add($this->stored(self::OPEN_ROUND, self::ACME_FOODS, self::UNASSIGNED_TICKET, RoundKind::Draft, self::MIA, 1, RoundState::Open));
            $this->rounds->add($this->draftWithAScreenshot(self::UNASSIGNED_DRAFT, self::UNASSIGNED_TICKET, self::OMAR));
        });

        $refused = $this->assertRefusedAndNothingSaved(
            'Round 1 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::UNASSIGNED_DRAFT, new UserId(self::OMAR), self::at()),
        );

        self::assertSame([1, null], [$refused->openRoundNumber(), $refused->readyForUserId()]);
    }

    #[Test]
    public function aShareOnATicketTheTicketQueriesCannotFindWhileARoundIsOpenFailsClosedAndSavesNothing(): void
    {
        $this->inTransaction(function (): void {
            $this->rounds->add($this->stored(self::OPEN_ROUND, self::ACME_FOODS, self::UNREADABLE_TICKET, RoundKind::Draft, self::MIA, 1, RoundState::Open));
            $this->rounds->add($this->draftWithAScreenshot(self::UNREADABLE_DRAFT, self::UNREADABLE_TICKET, self::OMAR));
        });
        $before = $this->snapshot();

        try {
            $this->inTransaction(fn (): int => $this->commands->shareDraft(self::UNREADABLE_DRAFT, new UserId(self::OMAR), self::at()));
            self::fail('The share went through on a ticket nobody could read.');
        } catch (TicketNotFound $notFound) {
            self::assertSame('That ticket does not exist.', $notFound->getMessage());
        }

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function afterTheOpenRoundClosesTheNextShareGetsTheNextNumber(): void
    {
        $omarsNumber = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));
        $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), self::at()),
        );
        $this->inTransaction(function (): void {
            $omarsRound = $this->rounds->get(new RoundId(self::OMARS_DRAFT)) ?? throw new \LogicException('Omar\'s round is missing.');
            $omarsRound->close();
            $this->rounds->save($omarsRound, self::at());
        });

        $miasNumber = $this->inTransaction(fn (): int => $this->commands->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), self::at()));

        self::assertSame([3, 4], [$omarsNumber, $miasNumber]);
    }

    #[Test]
    public function anEmptyDraftIsRefusedAsSharingBlockedAndStaysUnsharedAndUnnumbered(): void
    {
        $refused = $this->assertRefusedAndNothingSaved(
            'Add a screenshot before you share.',
            fn () => $this->commands->shareDraft(self::LENAS_EMPTY_DRAFT, new UserId(self::LENA), self::at()),
        );

        self::assertTrue($refused->isSharingBlocked());
        self::assertSame([false, false, false], [$refused->isNotFound(), $refused->isReadOnly(), $refused->isInvalidContent()]);
        self::assertSame([null, null], [$refused->openRoundNumber(), $refused->readyForUserId()]);
        self::assertSame([], $this->checklistCommands->findingsCreated);
    }

    #[Test]
    public function anEmptyDraftWhileARoundIsOpenIsToldAboutTheOpenRoundFirst(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Review, self::OMAR, 3);

        $this->assertRefusedAndNothingSaved(
            'Round 3 is still open. Wait for its verdict before you share.',
            fn () => $this->commands->shareDraft(self::LENAS_EMPTY_DRAFT, new UserId(self::LENA), self::at()),
        );
    }

    #[Test]
    public function sharingADraftThatIsSharedAlreadyIsReadOnlyAndNotTheWaitForItsOwnOpenRound(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::OMAR, 3);

        $refused = $this->assertRefusedAndNothingSaved(
            'This draft round is shared already.',
            fn () => $this->commands->shareDraft(self::OPEN_ROUND, new UserId(self::OMAR), self::at()),
        );

        self::assertTrue($refused->isReadOnly());
        self::assertFalse($refused->isSharingBlocked());
        self::assertNull($refused->openRoundNumber());
    }

    #[Test]
    public function aShareOutsideATransactionIsALogicExceptionAndSavesNothing(): void
    {
        $before = $this->snapshot();

        try {
            $this->commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at());
            self::fail('The share ran with no transaction open.');
        } catch (\LogicException $logic) {
            self::assertStringContainsString('none is open', $logic->getMessage());
        }

        self::assertSame($before, $this->snapshot());
    }

    /**
     * The assignee the share closes findings rounds for is read under the ticket's row lock, taken before the round
     * locks, as a move takes them; an unlocked read would let a give to Mia commit before Omar's share closes the round.
     */
    #[Test]
    public function theAssigneeIsReadUnderTheTicketsLockTakenBeforeTheRoundLocks(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::MIA, 3);
        $interfering = new InterferingRounds($this->rounds);
        $lockedWhenRoundsWereRead = null;
        $interfering->beforeOpenSharedRoundsOf = function () use (&$lockedWhenRoundsWereRead): void {
            $lockedWhenRoundsWereRead = $this->tickets->lockedTicketIds;
        };
        $commands = $this->commandsOver($interfering);

        $this->inTransaction(static fn (): int => $commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));

        self::assertSame([self::TICKET], $lockedWhenRoundsWereRead, 'The ticket was not locked before the rounds.');
        self::assertSame([self::TICKET], $this->tickets->lockedTicketIds);
        self::assertSame([], $this->tickets->askedTicketIds, 'The assignee came from an unlocked read.');
        self::assertSame(RoundState::Closed, $this->storedRound(self::OPEN_ROUND)->state());
    }

    /**
     * Round 3 closes and round 4's save finds it changed meanwhile, which its row lock rules out; the share and the close
     * of round 3 are saved by then, so only a LogicException makes the caller's transaction roll them back.
     */
    #[Test]
    public function aFindingsRoundThatCannotBeSavedClosedFailsTheShareSoItsTransactionRollsBack(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, RoundKind::Draft, self::MIA, 3);
        $this->addOpenRound(self::SECOND_OPEN_ROUND, RoundKind::Draft, self::LENA, 4);
        $interfering = new InterferingRounds($this->rounds);
        $interfering->refusedSaveRoundId = self::SECOND_OPEN_ROUND;
        $commands = $this->commandsOver($interfering);
        $before = $this->snapshot();
        $savesBefore = $this->rounds->savedRounds;

        try {
            $this->inTransaction(static fn (): int => $commands->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), self::at()));
            self::fail('The share went through though a findings round could not be closed.');
        } catch (\LogicException $failed) {
            self::assertSame(\sprintf('Round %s was read under a row lock, yet could not be closed.', self::SECOND_OPEN_ROUND), $failed->getMessage());
            self::assertInstanceOf(RoundChanged::class, $failed->getPrevious());
        }

        self::assertSame(2, $this->rounds->savedRounds - $savesBefore, 'The share and round 3\'s close were saved before round 4 failed.');
        self::assertSame($before, $this->snapshot(), 'The rollback leaves every round as it was.');
        self::assertSame([], $this->checklistCommands->findingsCreated);
    }

    #[Test]
    public function closingALockedRoundThatIsClosedAlreadyIsALogicExceptionAndSavesNothing(): void
    {
        $this->inTransaction(function (): void {
            $this->rounds->add($this->stored(self::OPEN_ROUND, self::ACME_FOODS, self::TICKET, RoundKind::Draft, self::MIA, 3, RoundState::Closed));
        });
        $before = $this->snapshot();
        $savesBefore = $this->rounds->savedRounds;

        try {
            $this->inTransaction(function (): void {
                $closed = $this->rounds->get(new RoundId(self::OPEN_ROUND)) ?? throw new \LogicException('Round 3 is seeded.');
                $this->commands->closeLockedRound($closed, self::at());
            });
            self::fail('A closed round was closed again.');
        } catch (\LogicException $failed) {
            self::assertSame(\sprintf('Round %s was read under a row lock, yet could not be closed.', self::OPEN_ROUND), $failed->getMessage());
            $refusal = $failed->getPrevious();
            self::assertInstanceOf(\DomainException::class, $refusal);
            self::assertSame('This round is closed already.', $refusal->getMessage());
        }

        self::assertSame([$before, $savesBefore], [$this->snapshot(), $this->rounds->savedRounds]);
    }

    private function commandsOver(Rounds $rounds): UseCaseRoundCommands
    {
        $uploadedMedia = new InMemoryUploadedMedia($this->tenantContext, [[self::ACME_FOODS, self::MEDIA, self::OMAR, MediaImageStatus::Ready]]);

        return new UseCaseRoundCommands($rounds, $this->tickets, $this->tenantContext, new ReportPictures($uploadedMedia), $this->checklistCommands);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    private function inTransaction(\Closure $write): mixed
    {
        return $this->transactions->run($write);
    }

    private function addOpenRound(string $roundId, RoundKind $kind, string $ownerId, int $number): void
    {
        $this->inTransaction(function () use ($roundId, $kind, $ownerId, $number): void {
            $this->rounds->add($this->stored($roundId, self::ACME_FOODS, self::TICKET, $kind, $ownerId, $number, RoundState::Open));
        });
    }

    /**
     * Adds chain A to Omar's draft: try 1 with a pin, then tries 2 and 3, each a retake of the try before.
     *
     * @return array{string, string, string, string} the ids of the seeded screenshot and of A's tries 1 to 3, by position
     */
    private function addChainOfThreeTriesToOmarsDraft(): array
    {
        $omar = new UserId(self::OMAR);
        $tryIds = array_map(static fn (int $try): ScreenshotId => new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-000000002c%02x', $try)), [1, 2, 3]);
        $this->inTransaction(function () use ($omar, $tryIds): void {
            $draft = $this->rounds->get(new RoundId(self::OMARS_DRAFT)) ?? throw new \LogicException('Omar\'s draft is seeded.');
            $draft->addScreenshot($omar, $tryIds[0], self::MEDIA);
            $draft->replacePins($omar, $tryIds[0], [Pin::placedBy(new PinId('0197a3c0-0000-7000-8000-000000002c11'), 1, 30.0, 40.0, 'Total is cut off', $omar)]);
            $draft->addScreenshot($omar, $tryIds[1], self::MEDIA, $tryIds[0]);
            $draft->addScreenshot($omar, $tryIds[2], self::MEDIA, $tryIds[1]);
            $this->rounds->save($draft, self::earlier());
        });
        $screenshotIds = array_map(static fn (Screenshot $screenshot): string => $screenshot->id()->toString(), $this->storedRound(self::OMARS_DRAFT)->screenshots());
        self::assertCount(4, $screenshotIds);

        return [$screenshotIds[0], $screenshotIds[1], $screenshotIds[2], $screenshotIds[3]];
    }

    /**
     * @param \Closure(): mixed $command
     */
    private function refusalOf(\Closure $command): string
    {
        try {
            $this->inTransaction($command);
        } catch (RoundRefused $refused) {
            return $refused->getMessage();
        }
        self::fail('The command was not refused.');
    }

    /**
     * @param \Closure(): mixed $share
     */
    private function assertRefusedAndNothingSaved(string $reason, \Closure $share): RoundRefused
    {
        $before = $this->snapshot();
        self::assertNotSame([], $before);
        $savesBefore = $this->rounds->savedRounds;
        $refusal = null;
        try {
            $this->inTransaction($share);
        } catch (RoundRefused $refused) {
            $refusal = $refused;
        }

        self::assertNotNull($refusal, 'The share was accepted; expected: '.$reason);
        self::assertSame($reason, $refusal->getMessage());
        self::assertSame($before, $this->snapshot());
        self::assertSame($savesBefore, $this->rounds->savedRounds);

        return $refusal;
    }

    /**
     * What a share may change in a stored round, by round id.
     *
     * @return array<string, array{number: int|null, sharedAt: string|null, state: string, version: int, decision: string|null}>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->rounds->stored as $round) {
            $snapshot[$round->id()->toString()] = [
                'number' => $round->number(),
                'sharedAt' => $round->sharedAt()?->format('c'),
                'state' => $round->state()->value,
                'version' => $round->version(),
                'decision' => $round->decision()?->value,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array<string, mixed>> $snapshot
     *
     * @return array<string, array<string, mixed>>
     */
    private static function without(array $snapshot, string ...$roundIds): array
    {
        return array_diff_key($snapshot, array_flip($roundIds));
    }

    private function storedRound(string $roundId): Round
    {
        foreach ($this->rounds->stored as $round) {
            if ($round->id()->toString() === $roundId) {
                return $round;
            }
        }

        throw new \LogicException('No stored round '.$roundId);
    }

    private function screenshot(): Screenshot
    {
        return Screenshot::of(new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x2A00 + ++$this->screenshotSeeds)), self::MEDIA, 0, null, null, []);
    }

    /**
     * A round as a read gives it, with one screenshot, shared at an earlier time.
     */
    private function stored(string $roundId, string $organizationId, string $ticketId, RoundKind $kind, string $ownerId, ?int $number, RoundState $state): Round
    {
        return Round::restored(
            new RoundId($roundId),
            new OrganizationId($organizationId),
            new TicketId($ticketId),
            $kind,
            new UserId($ownerId),
            $number,
            self::earlier(),
            $state,
            1,
            [$this->screenshot()],
            self::earlier(),
            null,
            null,
            null,
        );
    }

    private function draftWithAScreenshot(string $roundId, string $ticketId, string $ownerId): Round
    {
        $draft = self::draft($roundId, $ticketId, $ownerId);
        $draft->addScreenshot(new UserId($ownerId), new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x2A00 + ++$this->screenshotSeeds)), self::MEDIA);

        return $draft;
    }

    private static function draft(string $roundId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId($ownerId), self::earlier());
    }

    private static function earlier(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-03 08:00:00+00:00');
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }

    private static function ticketFacts(string $ticketId, ?UserId $assigneeId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-0000000027c1',
            '0197a3c0-0000-7000-8000-0000000027c2',
            'review',
            'check',
            new TeamId(self::SHOP),
            new UserId(self::LENA),
            'Lena',
            [new UserId(self::OMAR)],
            [new UserId(self::MIA)],
            'all',
            'major',
            null,
            new UserId(self::LENA),
            assigneeId: $assigneeId,
            reviewerId: new UserId(self::LENA),
            testerId: new UserId(self::MIA),
        );
    }
}
