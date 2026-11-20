<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\Review\Application\RoundShareDraftQuestion;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Domain\DraftDecision;
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
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Media\Application\InMemoryTransactions;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\MoveFacts;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\UndecidedDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Review's answer to "Share your draft round?" over InMemoryRounds, which keeps DbalRounds' version check and number
 * slots. Omar owns the draft with two screenshots that the question is about; Lena's draft on the same ticket, Mia's
 * closed shared round 1 and Omar's draft on another ticket are the rows each answer must leave alone.
 *
 * What a move closes (ADR-0048) runs on a second InMemoryRounds inside InMemoryTransactions, so its locked reads and
 * saves need an open transaction as on DbalRounds. It holds the same rounds plus Round 0, the open checklist round 2,
 * and open shared rounds 3 of another ticket and of another organization on the same ticket id, which no move closes
 * or waits for. Omar is the ticket's assignee and Lena its lead; each test adds the open rounds it is about.
 */
final class RoundShareDraftQuestionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002f02';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000002101';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000002102';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000002198';
    private const string LENA = '0197a3c0-0000-7000-8000-000000002b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002b02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002b03';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000002b04';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000002d01';

    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-000000002e01';
    private const string LENAS_DRAFT = '0197a3c0-0000-7000-8000-000000002e02';
    private const string MIAS_SHARED_ROUND = '0197a3c0-0000-7000-8000-000000002e03';
    private const string OMARS_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-000000002e04';
    private const string RAJS_EMPTY_DRAFT = '0197a3c0-0000-7000-8000-000000002e05';
    private const string REPORT = '0197a3c0-0000-7000-8000-000000002e06';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-000000002e07';
    private const string OTHER_TICKET_OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e08';
    private const string BETA_BAKERY_OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e09';
    /** The open round a move test adds, numbered 3. */
    private const string OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e0a';
    /** A second open round a move test adds, numbered 4. */
    private const string SECOND_OPEN_ROUND = '0197a3c0-0000-7000-8000-000000002e0b';

    private TenantContext $tenantContext;
    private InMemoryRounds $rounds;
    private RoundShareDraftQuestion $question;
    private InMemoryTransactions $transactions;
    private InMemoryRounds $roundsInTransactions;
    private RoundShareDraftQuestion $questionInTransactions;
    private int $screenshotSeeds = 0;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->rounds = new InMemoryRounds($this->tenantContext);
        $this->question = $this->questionOver($this->rounds);
        $this->seedRounds($this->rounds);

        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->roundsInTransactions = new InMemoryRounds($this->tenantContext, $this->transactions);
        $this->questionInTransactions = $this->questionOver($this->roundsInTransactions);
        $this->inTransaction(function (): void {
            $this->seedRounds($this->roundsInTransactions);
            $this->seedRoundsThatNoMoveCloses();
        });
    }

    #[Test]
    public function theQuestionIsAboutTheOwnersDraftWithItsScreenshotCountAndTheNextRoundNumber(): void
    {
        $draft = $this->question->undecidedDraftOf(new TicketId(self::TICKET), self::moveBy(self::OMAR));

        self::assertEquals(new UndecidedDraft(self::OMARS_DRAFT, 2, 2), $draft, 'Mia\'s round 1 makes Omar\'s the second.');
        self::assertSame([self::TICKET], $this->rounds->numberedTicketIds, 'The number is read under the lock sharing takes.');
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    /**
     * @return iterable<string, array{string, string}> who asks, on which ticket
     */
    public static function ownersWithNoQuestion(): iterable
    {
        yield 'a draft with no screenshot' => [self::RAJ, self::TICKET];
        yield 'only a shared round' => [self::MIA, self::TICKET];
        yield 'no draft on this ticket' => [self::LENA, self::OTHER_TICKET];
    }

    #[Test]
    #[DataProvider('ownersWithNoQuestion')]
    public function thereIsNoQuestionWithoutAnUndecidedDraftWithAScreenshot(string $owner, string $ticketId): void
    {
        self::assertNull($this->question->undecidedDraftOf(new TicketId($ticketId), self::moveBy($owner)));
        self::assertNull($this->question->undecidedDraftIdOf(new TicketId($ticketId), new UserId($owner)));
        self::assertSame([], $this->rounds->numberedTicketIds, 'No number is locked when nothing is asked.');
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    #[Test]
    public function aMoveWithItsAnswerGetsTheDraftIdWithoutReadingOrLockingANumber(): void
    {
        self::assertSame(self::OMARS_DRAFT, $this->question->undecidedDraftIdOf(new TicketId(self::TICKET), new UserId(self::OMAR)));
        self::assertSame([], $this->rounds->numberedTicketIds);
        self::assertSame(0, $this->rounds->loadedRounds, 'Only the draft\'s facts are read, not its screenshots and pins.');
    }

    #[Test]
    public function aDecidedDraftIsNotAskedAboutAgain(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->question->undecidedDraftOf(new TicketId(self::TICKET), self::moveBy(self::OMAR));
        self::assertNotNull($draft);
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $this->rounds->numberedTicketIds = [];

        self::assertNull($this->question->undecidedDraftOf(new TicketId(self::TICKET), self::moveBy(self::OMAR)));
        self::assertNull($this->question->undecidedDraftIdOf(new TicketId(self::TICKET), $omar));
        self::assertSame([], $this->rounds->numberedTicketIds, 'No number is locked for a decided draft.');
    }

    #[Test]
    public function sharingGivesTheDraftTheNextNumberAndRecordsSharedAndLeavesTheOtherRoundsAlone(): void
    {
        $before = $this->snapshot();
        $draft = $this->undecidedDraftOfOmar();
        $loadsBefore = $this->rounds->loadedRounds;
        $savesBefore = $this->rounds->savedRounds;

        $number = $this->question->share($draft->roundId, new UserId(self::OMAR), self::at());

        self::assertSame(2, $number);
        self::assertSame([1, 1], [$this->rounds->loadedRounds - $loadsBefore, $this->rounds->savedRounds - $savesBefore], 'The draft is read and saved once, shared and decided together.');
        $after = $this->snapshot();
        self::assertSame(
            [...$before[self::OMARS_DRAFT], 'number' => 2, 'sharedAt' => self::at()->format('c'), 'version' => $before[self::OMARS_DRAFT]['version'] + 2, 'decision' => 'shared', 'decidedAt' => self::at()->format('c')],
            $after[self::OMARS_DRAFT],
        );
        self::assertSame(array_diff_key($before, [self::OMARS_DRAFT => true]), array_diff_key($after, [self::OMARS_DRAFT => true]));
    }

    #[Test]
    public function keepingPrivateRecordsKeptPrivateWithNoNumberAndLeavesTheOtherRoundsAlone(): void
    {
        $before = $this->snapshot();
        $draft = $this->undecidedDraftOfOmar();

        $this->question->keepPrivate($draft->roundId, new UserId(self::OMAR), self::at());

        $after = $this->snapshot();
        self::assertSame(
            [...$before[self::OMARS_DRAFT], 'version' => $before[self::OMARS_DRAFT]['version'] + 1, 'decision' => 'kept_private', 'decidedAt' => self::at()->format('c')],
            $after[self::OMARS_DRAFT],
        );
        self::assertNull($after[self::OMARS_DRAFT]['sharedAt']);
        self::assertSame(array_diff_key($before, [self::OMARS_DRAFT => true]), array_diff_key($after, [self::OMARS_DRAFT => true]));
    }

    #[Test]
    public function anotherPersonCannotAnswerForTheOwnersDraft(): void
    {
        $draft = $this->undecidedDraftOfOmar();
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, new UserId(self::LENA), self::at()), RoundRefused::class);
        $this->assertChangedMeanwhile(fn () => $this->question->keepPrivate($draft->roundId, new UserId(self::LENA), self::at()), \DomainException::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function keepingPrivateADraftDecidedSinceItWasReadIsRefusedAndChangesNothing(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->keepPrivate($draft->roundId, $omar, self::at()), \DomainException::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function sharingADraftSharedSinceItWasReadIsRefusedAndChangesNothing(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->share($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function sharingADraftKeptPrivateSinceItWasReadIsRefusedAndNeitherSharesNorNumbersIt(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $this->question->keepPrivate($draft->roundId, $omar, self::at());
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
        self::assertNull($this->snapshot()[self::OMARS_DRAFT]['sharedAt']);
    }

    #[Test]
    public function sharingADraftSavedByAnotherRequestBetweenItsReadAndItsSaveIsRefused(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $interfering = new InterferingRounds($this->rounds);
        $interfering->afterNextGet = self::editOmarsDraftMeanwhile(...);
        $question = $this->questionOver($interfering);
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(static fn () => $question->share($draft->roundId, $omar, self::at()), RoundRefused::class);

        $after = $this->snapshot()[self::OMARS_DRAFT];
        self::assertSame([null, null, null], [$after['number'], $after['sharedAt'], $after['decision']]);
        self::assertSame($before[self::OMARS_DRAFT]['version'] + 1, $after['version'], 'Only the other request\'s edit is saved.');
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>|null}> the round id, and the refusal's cause
     */
    public static function draftIdsThatAreGone(): iterable
    {
        yield 'a malformed id' => ['not-a-round', \InvalidArgumentException::class];
        yield 'an id no round has' => ['0197a3c0-0000-7000-8000-000000002e99', null];
    }

    /**
     * @param class-string<\Throwable>|null $expectedCause
     */
    #[Test]
    #[DataProvider('draftIdsThatAreGone')]
    public function keepingPrivateADraftThatIsGoneIsRefusedAndChangesNothing(string $roundId, ?string $expectedCause): void
    {
        $before = $this->snapshot();

        try {
            $this->question->keepPrivate($roundId, new UserId(self::OMAR), self::at());
            self::fail('Keeping a gone draft private was not refused.');
        } catch (TicketChangedMeanwhile $refused) {
            self::assertSame('The draft round is gone.', $refused->getMessage());
            self::assertSame($expectedCause, null === $refused->getPrevious() ? null : $refused->getPrevious()::class);
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->rounds->savedRounds);
    }

    #[Test]
    public function sharingADraftThatIsGoneIsRefusedAndChangesNothing(): void
    {
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(fn () => $this->question->share('not-a-round', new UserId(self::OMAR), self::at()), RoundRefused::class);

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->rounds->savedRounds);
    }

    #[Test]
    public function aDraftSavedByAnotherRequestBetweenItsReadAndTheDecisionIsRefused(): void
    {
        $omar = new UserId(self::OMAR);
        $draft = $this->undecidedDraftOfOmar();
        $interfering = new InterferingRounds($this->rounds);
        $interfering->afterNextGet = self::editOmarsDraftMeanwhile(...);
        $question = $this->questionOver($interfering);
        $before = $this->snapshot();

        $this->assertChangedMeanwhile(static fn () => $question->keepPrivate($draft->roundId, $omar, self::at()), RoundChanged::class);

        self::assertNull($this->snapshot()[self::OMARS_DRAFT]['decision']);
        self::assertSame($before[self::OMARS_DRAFT]['version'] + 1, $this->snapshot()[self::OMARS_DRAFT]['version'], 'Only the other request\'s edit is saved.');
    }

    /**
     * @return iterable<string, array{MoveFacts}>
     */
    public static function leadOnlyMoves(): iterable
    {
        yield 'Lena\'s Put on hold on her turn' => [self::move(self::LENA, isLeadOnlyMove: true, isMoversTurn: true)];
        yield 'the assignee\'s Move to another step outside his turn' => [self::move(self::OMAR, isLeadOnlyMove: true, isMoversTurn: false)];
    }

    #[Test]
    #[DataProvider('leadOnlyMoves')]
    public function aLeadOnlyMoveClosesNoRoundAndReadsNone(MoveFacts $move): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);
        $before = $this->roundStates();

        $this->closeRoundsOnTheTicketsMove($move);

        self::assertSame($before, $this->roundStates(), 'Mia\'s findings round 3 stays open.');
        self::assertSame([], $this->roundsInTransactions->lockedOpenRoundTicketIds, 'No open round is read or locked.');
    }

    #[Test]
    public function theMoveOfThePersonWhoseTurnItIsClosesTheOpenRoundAndNoOther(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::OMAR, 3);
        $before = $this->roundStates();
        $savesBefore = $this->roundsInTransactions->savedRounds;

        $this->closeRoundsOnTheTicketsMove(self::move(self::MIA, isLeadOnlyMove: false, isMoversTurn: true));

        $after = $this->roundStates();
        self::assertSame([...$before[self::OPEN_ROUND], 'state' => 'closed', 'version' => $before[self::OPEN_ROUND]['version'] + 1], $after[self::OPEN_ROUND]);
        self::assertSame(self::without($before, self::OPEN_ROUND), self::without($after, self::OPEN_ROUND), 'Round 0, the checklist round and the other ticket\'s and organization\'s open rounds stay as they were.');
        self::assertSame(1, $this->roundsInTransactions->savedRounds - $savesBefore);
        self::assertSame([self::TICKET], $this->roundsInTransactions->lockedOpenRoundTicketIds);
    }

    #[Test]
    public function theAssigneesMoveOutsideTheirTurnClosesTheirFindingsRound(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);
        $before = $this->roundStates();

        $this->closeRoundsOnTheTicketsMove(self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: false));

        $after = $this->roundStates();
        self::assertSame('closed', $after[self::OPEN_ROUND]['state']);
        self::assertSame(self::without($before, self::OPEN_ROUND), self::without($after, self::OPEN_ROUND));
    }

    /**
     * @return iterable<string, array{MoveFacts}>
     */
    public static function movesThatCannotCloseARound(): iterable
    {
        yield 'Lena outside her turn' => [self::move(self::LENA, isLeadOnlyMove: false, isMoversTurn: false)];
        yield 'Omar outside his turn on a ticket nobody has' => [self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: false, assigneeId: null)];
    }

    #[Test]
    #[DataProvider('movesThatCannotCloseARound')]
    public function aMoveByNeitherThePersonWhoseTurnItIsNorTheAssigneeClosesNoRoundAndReadsNone(MoveFacts $move): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);
        $before = $this->roundStates();

        $this->closeRoundsOnTheTicketsMove($move);

        self::assertSame($before, $this->roundStates());
        self::assertSame([], $this->roundsInTransactions->lockedOpenRoundTicketIds);
    }

    #[Test]
    public function aRoundTheMoverSharedOutsideAMoveStaysOpenOnTheirOwnMoveWhileTheFindingsRoundCloses(): void
    {
        $this->addOpenRound(self::SECOND_OPEN_ROUND, self::MIA, 4);
        $this->addOpenRound(self::OPEN_ROUND, self::OMAR, 3);
        $before = $this->roundStates();

        $this->closeRoundsOnTheTicketsMove(self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: true));

        $after = $this->roundStates();
        self::assertSame($before[self::OPEN_ROUND], $after[self::OPEN_ROUND], 'Omar\'s round 3, shared with "Share now", waits for someone else\'s verdict.');
        self::assertSame('closed', $after[self::SECOND_OPEN_ROUND]['state'], 'Mia\'s findings round 4, after it, closes on Omar\'s turn.');
    }

    #[Test]
    public function aRoundTheMoverSharedInAnEarlierMoveIsClosedByTheirNextMoveOnTheirTurn(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::OMAR, 3, isSharedInAMove: true);

        $this->closeRoundsOnTheTicketsMove(self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: true));

        self::assertSame('closed', $this->roundStates()[self::OPEN_ROUND]['state']);
    }

    #[Test]
    public function closingRoundsOnAMoveNeedsTheMovesTransactionAndClosesNothingWithout(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);
        $before = $this->roundStates();

        try {
            $this->questionInTransactions->closeRoundsOnMove(new TicketId(self::TICKET), self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: true), self::at());
            self::fail('Rounds were closed outside a transaction.');
        } catch (\LogicException $refused) {
            self::assertSame('Rounds are read and saved in a transaction under the organization as tenant; none is open.', $refused->getMessage());
        }

        self::assertSame($before, $this->roundStates());
    }

    #[Test]
    public function aDraftCannotBeSharedInAMoveThatLeavesARoundOpenAndTheAnswerNamesTheLowestSuchRound(): void
    {
        $this->addOpenRound(self::SECOND_OPEN_ROUND, self::LENA, 4);
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);
        $before = $this->roundStates();

        $draft = $this->inTransaction(fn () => $this->questionInTransactions->undecidedDraftOf(new TicketId(self::TICKET), self::move(self::LENA, isLeadOnlyMove: true, isMoversTurn: true)));

        self::assertEquals(new UndecidedDraft(self::LENAS_DRAFT, 1, 5, 3, new UserId(self::OMAR)), $draft, 'Mia\'s round 3, added after round 4, waits for Omar.');
        self::assertNotNull($draft);
        self::assertTrue($draft->isSharingBlocked());
        self::assertSame($before, $this->roundStates(), 'Asking closes nothing.');
        self::assertSame([[self::TICKET], [self::TICKET]], [$this->roundsInTransactions->lockedOpenRoundTicketIds, $this->roundsInTransactions->numberedTicketIds]);
    }

    #[Test]
    public function theAssigneesOwnRoundSharedOutsideAMoveBlocksSharingTheirDraftInTheirMove(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::OMAR, 3);

        $draft = $this->inTransaction(fn () => $this->questionInTransactions->undecidedDraftOf(new TicketId(self::TICKET), self::move(self::OMAR, isLeadOnlyMove: false, isMoversTurn: true)));

        self::assertEquals(new UndecidedDraft(self::OMARS_DRAFT, 2, 4, 3, null), $draft, 'Neither his move nor his share closes his own round; its verdict is whoever\'s turn it is.');
    }

    #[Test]
    public function aRoundTheMoveClosesDoesNotBlockSharingTheDraftInIt(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::OMAR, 3);
        $before = $this->roundStates();

        $draft = $this->inTransaction(fn () => $this->questionInTransactions->undecidedDraftOf(new TicketId(self::TICKET), self::move(self::LENA, isLeadOnlyMove: false, isMoversTurn: true)));

        self::assertEquals(new UndecidedDraft(self::LENAS_DRAFT, 1, 4), $draft, 'The move closes round 3 first, so Lena\'s draft is shared as round 4.');
        self::assertSame($before, $this->roundStates(), 'Asking closes nothing; the move closes it.');
    }

    #[Test]
    public function theAssigneesFindingsRoundDoesNotBlockTheirDraftSinceTheirShareClosesIt(): void
    {
        $this->addOpenRound(self::OPEN_ROUND, self::MIA, 3);

        $draft = $this->inTransaction(fn () => $this->questionInTransactions->undecidedDraftOf(new TicketId(self::TICKET), self::move(self::OMAR, isLeadOnlyMove: true, isMoversTurn: false)));

        self::assertEquals(new UndecidedDraft(self::OMARS_DRAFT, 2, 4), $draft, 'The lead-only move closes nothing, but Omar\'s share closes his findings round 3.');
    }

    #[Test]
    public function anEmptyDraftsShareIsRefusedWithItsReasonAndNoOpenRound(): void
    {
        $before = $this->roundStates();

        try {
            $this->inTransaction(fn () => $this->questionInTransactions->share(self::RAJS_EMPTY_DRAFT, new UserId(self::RAJ), self::at()));
            self::fail('The share was not refused.');
        } catch (MoveRefused $refused) {
            self::assertSame('Add a screenshot before you share.', $refused->ruleNoteTemplate);
            self::assertNull($refused->openRoundNumber());
            self::assertNull($refused->openRoundReadyForUserId());
        }
        self::assertSame($before, $this->roundStates());
    }

    private static function editOmarsDraftMeanwhile(Rounds $rounds): void
    {
        $stored = $rounds->get(new RoundId(self::OMARS_DRAFT)) ?? throw new \LogicException('Omar\'s draft is seeded.');
        $stored->editScreenshotText(new UserId(self::OMAR), new ScreenshotId('0197a3c0-0000-7000-8000-000000002a01'), 'Edited meanwhile', null);
        $rounds->save($stored, self::at());
    }

    private function questionOver(Rounds $rounds): RoundShareDraftQuestion
    {
        $commands = new UseCaseRoundCommands(
            $rounds,
            new InMemoryTicketTurnQueries([], new TicketId(self::BROKEN_TICKET)),
            $this->tenantContext,
            new ReportPictures(new InMemoryUploadedMedia($this->tenantContext, [])),
            new RecordingChecklistCommands(),
        );

        return new RoundShareDraftQuestion($rounds, $commands, $commands);
    }

    private function undecidedDraftOfOmar(): UndecidedDraft
    {
        return $this->question->undecidedDraftOf(new TicketId(self::TICKET), self::moveBy(self::OMAR)) ?? throw new \LogicException('Omar has an undecided draft.');
    }

    /**
     * @param \Closure(): mixed        $answer
     * @param class-string<\Throwable> $expectedCause
     */
    private function assertChangedMeanwhile(\Closure $answer, string $expectedCause): void
    {
        try {
            $answer();
            self::fail('The answer was not refused.');
        } catch (TicketChangedMeanwhile $refused) {
            self::assertInstanceOf($expectedCause, $refused->getPrevious());
        }
    }

    private function seedRounds(InMemoryRounds $rounds): void
    {
        $omar = new UserId(self::OMAR);
        $omarsDraft = self::draftOn(self::OMARS_DRAFT, self::TICKET, self::OMAR);
        $omarsDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a01'), self::MEDIA);
        $omarsDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a02'), self::MEDIA);
        $rounds->add($omarsDraft);

        $lena = new UserId(self::LENA);
        $lenasDraft = self::draftOn(self::LENAS_DRAFT, self::TICKET, self::LENA);
        $lenasDraft->addScreenshot($lena, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a03'), self::MEDIA);
        $rounds->add($lenasDraft);

        $mia = new UserId(self::MIA);
        $miasShared = self::draftOn(self::MIAS_SHARED_ROUND, self::TICKET, self::MIA);
        $miasShared->addScreenshot($mia, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a04'), self::MEDIA);
        $miasShared->share($mia, 1, self::at());
        // Closed, so it holds back no share (ADR-0048); UseCaseRoundCommandsShareTest covers an open one.
        $miasShared->close();
        $rounds->add($miasShared);

        $otherTicketDraft = self::draftOn(self::OMARS_OTHER_TICKET_DRAFT, self::OTHER_TICKET, self::OMAR);
        $otherTicketDraft->addScreenshot($omar, new ScreenshotId('0197a3c0-0000-7000-8000-000000002a05'), self::MEDIA);
        $rounds->add($otherTicketDraft);

        $rounds->add(self::draftOn(self::RAJS_EMPTY_DRAFT, self::TICKET, self::RAJ));
    }

    private function seedRoundsThatNoMoveCloses(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-03 08:00:00+00:00');
        $this->roundsInTransactions->add(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [$this->screenshot()], $createdAt));
        $this->roundsInTransactions->add(Round::restored(
            new RoundId(self::CHECKLIST),
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            RoundKind::Checklist,
            new UserId(self::LENA),
            2,
            $createdAt,
            RoundState::Open,
            1,
            [$this->screenshot()],
            $createdAt,
            null,
            null,
            null,
        ));
        $this->roundsInTransactions->add($this->sharedDraft(self::OTHER_TICKET_OPEN_ROUND, self::ACME_FOODS, self::OTHER_TICKET, self::MIA, 3));
        $this->roundsInTransactions->add($this->sharedDraft(self::BETA_BAKERY_OPEN_ROUND, self::BETA_BAKERY, self::TICKET, self::MIA, 3));
    }

    /**
     * Adds an open shared draft of $ownerId to the ticket. "Share now" records no decision; a share in a move records
     * "shared".
     */
    private function addOpenRound(string $roundId, string $ownerId, int $number, bool $isSharedInAMove = false): void
    {
        $this->inTransaction(function () use ($roundId, $ownerId, $number, $isSharedInAMove): void {
            $this->roundsInTransactions->add($this->sharedDraft($roundId, self::ACME_FOODS, self::TICKET, $ownerId, $number, $isSharedInAMove));
        });
    }

    private function closeRoundsOnTheTicketsMove(MoveFacts $move): void
    {
        $this->inTransaction(function () use ($move): void {
            $this->questionInTransactions->closeRoundsOnMove(new TicketId(self::TICKET), $move, self::at());
        });
    }

    private function sharedDraft(string $roundId, string $organizationId, string $ticketId, string $ownerId, int $number, bool $isSharedInAMove = false): Round
    {
        $owner = new UserId($ownerId);
        $draft = Round::draft(new RoundId($roundId), new OrganizationId($organizationId), new TicketId($ticketId), $owner, new \DateTimeImmutable('2026-03-03 08:00:00+00:00'));
        $draft->addScreenshot($owner, $this->nextScreenshotId(), self::MEDIA);
        $draft->share($owner, $number, new \DateTimeImmutable('2026-03-03 09:00:00+00:00'));
        if ($isSharedInAMove) {
            $draft->decide($owner, DraftDecision::Shared, new \DateTimeImmutable('2026-03-03 09:00:00+00:00'));
        }

        return $draft;
    }

    private function screenshot(): Screenshot
    {
        return Screenshot::of($this->nextScreenshotId(), self::MEDIA, 0, null, null, []);
    }

    private function nextScreenshotId(): ScreenshotId
    {
        return new ScreenshotId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x2C00 + ++$this->screenshotSeeds));
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

    /**
     * @return array<string, array{state: string, version: int, number: int|null, decision: string|null}>
     */
    private function roundStates(): array
    {
        $states = [];
        foreach ($this->roundsInTransactions->stored as $round) {
            $states[$round->id()->toString()] = [
                'state' => $round->state()->value,
                'version' => $round->version(),
                'number' => $round->number(),
                'decision' => $round->decision()?->value,
            ];
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $states
     *
     * @return array<string, mixed>
     */
    private static function without(array $states, string $roundId): array
    {
        unset($states[$roundId]);

        return $states;
    }

    /**
     * @return array<string, array{number: int|null, sharedAt: string|null, version: int, decision: string|null, decidedAt: string|null, screenshots: int}>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->rounds->stored as $round) {
            $snapshot[$round->id()->toString()] = [
                'number' => $round->number(),
                'sharedAt' => $round->sharedAt()?->format('c'),
                'version' => $round->version(),
                'decision' => $round->decision()?->value,
                'decidedAt' => $round->decidedAt()?->format('c'),
                'screenshots' => \count($round->screenshots()),
            ];
        }

        return $snapshot;
    }

    private static function draftOn(string $roundId, string $ticketId, string $ownerId): Round
    {
        return Round::draft(new RoundId($roundId), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId($ownerId), new \DateTimeImmutable('2026-03-03 08:00:00+00:00'));
    }

    /**
     * A move-on verb by $moverId on a ticket nobody has, when it is not their turn, so it closes no round.
     */
    private static function moveBy(string $moverId): MoveFacts
    {
        return new MoveFacts(new UserId($moverId), null, false, false);
    }

    /**
     * A move on the ticket Omar has, unless $assigneeId says otherwise.
     */
    private static function move(string $moverId, bool $isLeadOnlyMove, bool $isMoversTurn, ?string $assigneeId = self::OMAR): MoveFacts
    {
        return new MoveFacts(new UserId($moverId), null === $assigneeId ? null : new UserId($assigneeId), $isLeadOnlyMove, $isMoversTurn);
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 09:30:00+00:00');
    }
}
